/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request';

export interface PreviewFrameSettings {
  url: string;
  renewUrl: string;
  page: number;
  hosts: string[];
  expiresIn: number;
}

export interface PreviewFrameCallbacks {
  onLocation?: (address: URL, page: number) => void;
  onFailure?: () => void;
}

const parameter = '__t3ct';
const renewBefore = 60_000;

/**
 * The query is handled as text: only pairs named like the token are touched, all other
 * pairs keep their bytes, as signed addresses and redirects compare the query as it is.
 */
function isTokenPair(pair: string): boolean {
  try {
    return decodeURIComponent(pair.split('=', 1)[0].replace(/\+/g, ' ')) === parameter;
  } catch {
    return false;
  }
}

function queryPairs(url: URL): string[] {
  return url.search === '' ? [] : url.search.slice(1).split('&');
}

function readToken(url: URL): string | null {
  const pair = queryPairs(url).find(isTokenPair);
  if (pair === undefined) {
    return null;
  }
  const value = pair.includes('=') ? pair.slice(pair.indexOf('=') + 1) : '';
  try {
    return decodeURIComponent(value.replace(/\+/g, ' '));
  } catch {
    return value;
  }
}

/**
 * Module: @typo3/backend/preview/preview-frame
 *
 * Runs in the authenticated backend document around a frame that shows a site with a
 * preview token. It renews the token before it expires, loads navigations to other
 * hosts of the preview with a fresh token and never hands a backend credential to the frame.
 */
export default class PreviewFrame {
  private address: URL;
  private token: string | null;
  private hosts: string[];
  private page: number;
  private expiresAt: number;
  private timer: number = 0;
  private renewal: Promise<string> | null = null;
  private readonly failures = new Map<string, Set<string>>();
  private readonly listeners = new AbortController();

  public constructor(
    private readonly frame: HTMLIFrameElement,
    private readonly settings: PreviewFrameSettings,
    private readonly callbacks: PreviewFrameCallbacks = {},
  ) {
    this.address = PreviewFrame.withoutToken(new URL(settings.url));
    this.token = readToken(new URL(settings.url));
    this.hosts = settings.hosts;
    this.page = settings.page;
    this.expiresAt = Date.now() + settings.expiresIn * 1000;
    if (this.token === null) {
      // An address outside the sites of the installation is shown as it is, as before
      this.frame.src = this.address.href;
      return;
    }
    this.schedule();
    window.addEventListener('message', this.receive, { signal: this.listeners.signal });
    document.addEventListener('visibilitychange', (): void => {
      if (document.visibilityState !== 'visible') {
        return;
      }
      if (this.expiresAt - Date.now() >= renewBefore) {
        return;
      }
      this.renew();
    }, { signal: this.listeners.signal });
    // Loaded only now, so the first report of the frame cannot arrive before anybody listens
    this.frame.src = settings.url;
  }

  public static withoutToken(url: URL): URL {
    const clean = new URL(url);
    const pairs = queryPairs(url);
    const kept = pairs.filter((pair: string): boolean => !isTokenPair(pair));
    if (kept.length !== pairs.length) {
      clean.search = kept.length === 0 ? '' : '?' + kept.join('&');
    }
    return clean;
  }

  public reload(): void {
    if (this.token === null) {
      this.frame.src = this.address.href;
      return;
    }
    this.load(this.address);
  }

  public dispose(): void {
    window.clearTimeout(this.timer);
    this.listeners.abort();
  }

  private allows(url: URL): boolean {
    if (!['http:', 'https:'].includes(url.protocol)) {
      return false;
    }
    return this.hosts.includes(url.host.toLowerCase());
  }

  private readonly receive = (event: MessageEvent): void => {
    if (event.source !== this.frame.contentWindow) {
      return;
    }
    if (typeof event.data?.type !== 'string') {
      return;
    }
    let origin: URL;
    let url: URL;
    try {
      origin = new URL(event.origin);
      url = new URL(String(event.data.url));
    } catch {
      return;
    }
    if (!this.allows(origin)) {
      return;
    }
    switch (event.data.type) {
      case 'typo3:preview:location':
        if (url.origin === origin.origin) {
          this.address = PreviewFrame.withoutToken(url);
          this.page = Number.isInteger(event.data.page) ? event.data.page : this.page;
          // The page was shown, so a later refusal at its address is recovered again
          this.failures.delete(this.address.href);
          // A renewal posted while the frame was loading this page reached the page before it
          this.frame.contentWindow?.postMessage({ type: 'typo3:preview:token', token: this.token }, origin.origin);
          this.callbacks.onLocation?.(this.address, this.page);
        }
        break;
      case 'typo3:preview:navigate':
        this.navigate(url);
        break;
      case 'typo3:preview:expired':
        if (url.origin === origin.origin) {
          this.recover(PreviewFrame.withoutToken(url), String(event.data.reason));
        }
        break;
      case 'typo3:preview:admin-panel':
        this.relayAdminPanel(event.data.action, event.data.fields);
        break;
      default:
        break;
    }
  };

  /**
   * A host of the preview loads in the frame with a fresh token, another address of the
   * web opens in a new tab. A script of the site can ask for any address, so nothing
   * else opens, such as javascript: or data:.
   */
  private navigate(url: URL): void {
    if (this.allows(url)) {
      this.load(url);
      return;
    }
    if (!['http:', 'https:'].includes(url.protocol)) {
      return;
    }
    window.open(PreviewFrame.withoutToken(url).href, '_blank', 'noopener,noreferrer');
  }

  /**
   * The admin panel in the frame cannot reach the backend: the frame has no backend
   * login. It hands its settings to this document, which stores them with the backend
   * session of the editor and reloads the frame.
   */
  private relayAdminPanel(action: unknown, fields: unknown): void {
    const ajaxUrls: Record<string, string> = TYPO3.settings?.ajaxUrls ?? {};
    if (action === 'toggle') {
      if (typeof ajaxUrls.adminPanel_toggle !== 'string') {
        return;
      }
      new AjaxRequest(ajaxUrls.adminPanel_toggle).get().then((): void => this.reload(), (): void => this.fail());
      return;
    }
    if (action !== 'save') {
      return;
    }
    if (typeof ajaxUrls.adminPanel_saveForm !== 'string') {
      return;
    }
    if (!Array.isArray(fields)) {
      return;
    }
    const data = new FormData();
    fields.forEach((field: unknown): void => {
      if (!Array.isArray(field)) {
        return;
      }
      if (typeof field[0] !== 'string') {
        return;
      }
      if (typeof field[1] !== 'string') {
        return;
      }
      data.append(field[0], field[1]);
    });
    new AjaxRequest(ajaxUrls.adminPanel_saveForm).post(data).then((): void => this.reload(), (): void => this.fail());
  }

  /**
   * Reloads the frame with a fresh token once per address and reason until the page is
   * shown there; a second refusal before that stops, so a token the site keeps refusing
   * never loops.
   */
  private recover(address: URL, reason: string): void {
    const reasons = this.failures.get(address.href) ?? new Set<string>();
    if (reasons.has(reason)) {
      this.fail();
      return;
    }
    this.failures.set(address.href, reasons.add(reason));
    this.load(address);
  }

  private load(address: URL): void {
    this.address = PreviewFrame.withoutToken(address);
    this.sign(this.address).then((url: string): void => {
      this.frame.src = url;
    }).catch((): void => this.fail());
  }

  private renew(): void {
    const address = this.address;
    this.sign(address).then((url: string): void => {
      const token = readToken(new URL(url));
      this.frame.contentWindow?.postMessage({ type: 'typo3:preview:token', token }, address.origin);
    }).catch((): void => this.fail());
  }

  private sign(address: URL): Promise<string> {
    if (this.renewal === null) {
      this.renewal = new AjaxRequest(this.settings.renewUrl)
        .post({ previewToken: this.token, url: address.href, page: this.page })
        .then(response => response.resolve())
        .then((result: { url: string, expiresIn: number, hosts?: unknown }): string => {
          // The hosts of the preview are resolved again with every token
          if (Array.isArray(result.hosts) && result.hosts.every((host: unknown): host is string => typeof host === 'string')) {
            this.hosts = result.hosts;
          }
          const url = new URL(result.url);
          if (!this.allows(url)) {
            throw new Error('Preview token signed for another address');
          }
          if (url.origin !== address.origin) {
            throw new Error('Preview token signed for another address');
          }
          this.token = readToken(url);
          this.expiresAt = Date.now() + result.expiresIn * 1000;
          this.schedule();
          return url.href;
        })
        .finally((): void => {
          this.renewal = null;
        });
    }
    return this.renewal;
  }

  private schedule(): void {
    window.clearTimeout(this.timer);
    this.timer = window.setTimeout(() => this.renew(), Math.max(1000, this.expiresAt - Date.now() - renewBefore));
  }

  private fail(): void {
    window.clearTimeout(this.timer);
    this.callbacks.onFailure?.();
  }
}
