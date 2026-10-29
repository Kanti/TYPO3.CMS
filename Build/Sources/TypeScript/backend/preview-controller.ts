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
import { allowsPreviewUrl, type PreviewScope } from '@typo3/core/preview-navigation';
import { topWindow } from '@typo3/core/utility/top-window';

/** The authenticated backend owns renewal and cross-origin frame navigation. */
export default class PreviewController {
  private url: URL;
  private token: string;
  private readonly scope: PreviewScope;
  private timer: number;
  private expires: number;
  private pending: Promise<void> | null = null;
  private restored = false;
  private hasHistory = false;
  private readonly hostWindow: Window;
  private readonly events = new AbortController();
  private stopped = false;
  public constructor(private target: Window, url: string, private frame?: HTMLIFrameElement, private readonly onNavigate?: (url: URL) => void) {
    this.hostWindow = frame ? window : topWindow;
    this.url = new URL(url);
    this.token = this.url.searchParams.get('_ps');
    const claims = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(this.token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')), character => character.charCodeAt(0))));
    this.scope = claims.scope;
    this.hostWindow.addEventListener('message', this.receive, { signal: this.events.signal });
    this.schedule(claims.exp);
    this.hostWindow.document.addEventListener('visibilitychange', () => {
      if (this.hostWindow.document.visibilityState === 'visible') {
        this.renew().catch(() => this.stop());
      }
    }, { signal: this.events.signal });
    this.hostWindow.addEventListener('pagehide', (event: PageTransitionEvent) => {
      if (!event.persisted) {
        this.stop();
      }
    }, { signal: this.events.signal });
    this.hostWindow.addEventListener('pageshow', () => this.renew().catch(() => this.stop()), { signal: this.events.signal });
    if (this.frame) {
      this.frame.addEventListener('load', () => this.connect());
      this.connect();
      topWindow.addEventListener('popstate', this.restore, { signal: this.events.signal });
      const restored = this.historyUrl();
      const initial = new URL(this.url);
      initial.searchParams.delete('_ps');
      if (restored && restored.href !== initial.href) {
        this.url = restored;
        this.restored = true;
        this.loadFrame();
      }
    }
  }

  public static open(target: Window, url: string): void {
    const frontend = new URL(url);
    const shell = new URL(topWindow.TYPO3.settings.previewUrl, topWindow.location.href);
    shell.searchParams.set('previewToken', frontend.searchParams.get('_ps'));
    frontend.searchParams.delete('_ps');
    shell.searchParams.set('previewUrl', frontend.href);
    target.location.href = shell.href;
  }

  public refresh(): void {
    this.renew().then(() => {
      if (this.stopped) {
        return;
      }
      if (this.frame) {
        this.loadFrame();
      } else {
        this.target.location.href = this.signedUrl().href;
      }
    }).catch(() => this.stop());
  }

  public dispose(): void {
    this.stop();
  }

  private readonly receive = (event: MessageEvent): void => {
    if (event.source !== this.target || !event.data || typeof event.data.url !== 'string') {
      return;
    }
    let url: URL;
    try {
      url = new URL(event.data.url);
    } catch {
      return;
    }
    if (event.origin !== url.origin || !allowsPreviewUrl(this.scope, url)) {
      // Navigation messages may name another authorized origin, but their sender must
      // still be the currently displayed document.
      if (event.origin !== this.url.origin || !allowsPreviewUrl(this.scope, url)) {
        return;
      }
    }
    if (event.data.type === 'typo3-preview-navigate') {
      this.navigate(url);
    } else if (event.data.type === 'typo3-preview-ready') {
      this.url = url;
      this.target.postMessage({ type: 'typo3-preview-token', url: this.signedUrl().href }, url.origin);
      this.synchronize();
    }
  };

  private readonly restore = (): void => {
    const url = this.historyUrl();
    if (url) {
      this.navigate(url, true);
    }
  };

  private historyUrl(): URL | null {
    const value = new URL(topWindow.location.href).searchParams.get('previewUrl');
    try {
      const url = new URL(value);
      return allowsPreviewUrl(this.scope, url) ? url : null;
    } catch {
      return null;
    }
  }

  private navigate(url: URL, restore = false): void {
    if (!allowsPreviewUrl(this.scope, url)) {
      return;
    }
    this.url = url;
    this.restored = restore;
    this.renew().then(() => {
      if (this.stopped) {
        return;
      }
      if (this.frame) {
        // Parent assignment is allowed across origins; accessing contentWindow.location is not.
        this.loadFrame();
      } else {
        this.target.location.href = this.signedUrl().href;
      }
    }).catch(() => this.stop());
  }

  private loadFrame(): void {
    // A fresh browsing context avoids adding an implicit iframe history entry
    // alongside the explicit backend history entry.
    const frame = this.frame.cloneNode(false) as HTMLIFrameElement;
    frame.src = this.signedUrl().href;
    this.frame.replaceWith(frame);
    this.frame = frame;
    this.target = frame.contentWindow;
    frame.addEventListener('load', () => this.connect());
  }

  private connect(): void {
    this.target.postMessage({ type: 'typo3-preview-connect' }, this.url.origin);
  }

  private renew(): Promise<void> {
    if (this.pending !== null) {
      return this.pending;
    }
    if (Date.now() < this.expires * 1000 - 60000) {
      return Promise.resolve();
    }
    this.pending = new AjaxRequest(topWindow.TYPO3.settings.ajaxUrls.preview_renew)
      .addMiddleware(request => this.hostWindow.fetch(request))
      .post({ previewToken: this.token, url: this.url.href }, { signal: this.events.signal })
      .then(response => response.resolve())
      .then((result: { url: string; expires: number }) => {
        if (this.stopped) {
          return;
        }
        const url = new URL(result.url);
        if (!allowsPreviewUrl(this.scope, url)) {
          throw new Error('Invalid preview renewal destination');
        }
        this.token = url.searchParams.get('_ps');
        this.target.postMessage({ type: 'typo3-preview-token', url: this.signedUrl().href }, this.url.origin);
        this.schedule(result.expires);
      }).finally(() => { this.pending = null; });
    return this.pending;
  }

  private signedUrl(): URL {
    const url = new URL(this.url);
    url.searchParams.set('_ps', this.token);
    return url;
  }

  private schedule(expires: number): void {
    this.expires = expires;
    this.hostWindow.clearTimeout(this.timer);
    this.timer = this.hostWindow.setTimeout(() => this.renew().catch(() => this.stop()), Math.max(1000, expires * 1000 - Date.now() - 60000));
  }

  private synchronize(): void {
    if (!this.frame) {
      return;
    }
    const current = new URL(topWindow.location.href);
    const clean = new URL(this.url);
    clean.searchParams.delete('_ps');
    this.onNavigate?.(clean);
    if (current.searchParams.get('previewUrl') === clean.href) {
      this.restored = false;
      this.hasHistory = true;
      return;
    }
    current.searchParams.set('previewUrl', clean.href);
    const state = { ...topWindow.history.state };
    if (typeof state.url === 'string') {
      const module = new URL(state.url, topWindow.location.href);
      module.searchParams.set('previewUrl', clean.href);
      state.url = module.href;
    }
    const operation = this.restored || !this.hasHistory ? 'replaceState' : 'pushState';
    if (window !== topWindow) {
      const moduleUrl = new URL(window.location.href);
      moduleUrl.searchParams.set('previewUrl', clean.href);
      window.history.replaceState(window.history.state, '', moduleUrl);
    }
    topWindow.history[operation](state, '', current);
    this.hasHistory = true;
    this.restored = false;
  }

  private stop(): void {
    this.hostWindow.clearTimeout(this.timer);
    this.stopped = true;
    this.events.abort();
  }
}
