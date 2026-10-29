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

export type PreviewScope = Record<string, Record<string, string>>;

export function allowsPreviewUrl(scope: PreviewScope, url: URL): boolean {
  return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password
    && Object.values(scope).some(languages => Object.values(languages).some(value => {
      const base = new URL(value);
      const path = base.pathname.replace(/\/$/, '');
      return url.origin === base.origin && (url.pathname === path || url.pathname.startsWith(path + '/'));
    }));
}

type PreviewNavigateEvent = Event & { destination: { url: string; sameDocument: boolean }; hashChange: boolean; formData?: FormData | null };

/** Keeps document navigation in the signed preview without depending on cookies. */
export default class PreviewNavigation {
  private token = new URL(window.location.href).searchParams.get('_ps');
  private readonly host: Window = window.parent !== window ? window.parent : window.opener;

  public constructor(private readonly parentOrigin: string, private readonly scope: PreviewScope) {
    window.addEventListener('message', (event: MessageEvent) => {
      if (event.source !== this.host || event.origin !== this.parentOrigin) {
        return;
      }
      if (event.data?.type === 'typo3-preview-connect') {
        this.host.postMessage({ type: 'typo3-preview-ready', url: this.clean(new URL(window.location.href)).href }, this.parentOrigin);
        return;
      }
      if (event.data?.type !== 'typo3-preview-token' || typeof event.data.url !== 'string') {
        return;
      }
      const url = new URL(event.data.url);
      if (!allowsPreviewUrl(this.scope, url) || !url.searchParams.has('_ps')) {
        return;
      }
      this.token = url.searchParams.get('_ps');
      document.querySelectorAll<HTMLAnchorElement>('a[href]').forEach(link => {
        const target = new URL(link.href);
        if (allowsPreviewUrl(this.scope, target)) {
          target.searchParams.set('_ps', this.token);
          link.href = target.href;
        }
      });
      const current = new URL(window.location.href);
      current.searchParams.set('_ps', this.token);
      if (current.href !== window.location.href) {
        window.history.replaceState(window.history.state, '', current);
      }
    });

    const navigation = (window as Window & { navigation?: EventTarget }).navigation;
    navigation?.addEventListener('navigate', (event: PreviewNavigateEvent) => {
      if (!event.cancelable || event.destination.sameDocument || event.hashChange || event.formData) {
        return;
      }
      const url = new URL(event.destination.url);
      if (!allowsPreviewUrl(this.scope, url) && url.searchParams.has('_ps')) {
        event.preventDefault();
        window.location.assign(this.clean(url));
        return;
      }
      if (allowsPreviewUrl(this.scope, url)) {
        if (this.host && !this.host.closed) {
          event.preventDefault();
          this.host.postMessage({ type: 'typo3-preview-navigate', url: this.clean(url).href }, this.parentOrigin);
        } else if (url.searchParams.get('_ps') !== this.token) {
          event.preventDefault();
          url.searchParams.set('_ps', this.token);
          window.location.assign(url);
        }
      }
    });

    // Also handles browsers without Navigation API and links targeting the top frame.
    document.addEventListener('click', (event: MouseEvent) => {
      const link = (event.target as Element)?.closest<HTMLAnchorElement>('a[href]');
      if (!link || event.defaultPrevented || event.button !== 0 || link.hasAttribute('download')) {
        return;
      }
      const url = new URL(link.href);
      if (!allowsPreviewUrl(this.scope, url)) {
        if (url.searchParams.has('_ps')) {
          link.href = this.clean(url).href;
        }
        return;
      }
      url.searchParams.set('_ps', this.token);
      link.href = url.href;
      if (this.host && !this.host.closed && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && link.target !== '_blank') {
        event.preventDefault();
        this.host.postMessage({ type: 'typo3-preview-navigate', url: this.clean(url).href }, this.parentOrigin);
      }
    }, true);

    document.addEventListener('submit', (event: SubmitEvent) => {
      const form = event.target as HTMLFormElement;
      const submitter = event.submitter as HTMLButtonElement | HTMLInputElement;
      const url = new URL(submitter?.hasAttribute('formaction') ? submitter.formAction : form.action || window.location.href);
      if (!allowsPreviewUrl(this.scope, url)) {
        form.action = this.clean(url).href;
        if (submitter?.hasAttribute('formaction')) {
          submitter.formAction = form.action;
        }
        form.querySelectorAll('[name="_ps"]').forEach(input => input.remove());
        return;
      }
      if ((submitter?.formMethod || form.method).toLowerCase() === 'get') {
        url.search = new URLSearchParams(new FormData(form, submitter) as unknown as Record<string, string>).toString();
        url.searchParams.set('_ps', this.token);
        if (this.host && !this.host.closed) {
          event.preventDefault();
          this.host.postMessage({ type: 'typo3-preview-navigate', url: this.clean(url).href }, this.parentOrigin);
        } else {
          let input = form.querySelector<HTMLInputElement>('input[name="_ps"]');
          if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_ps';
            form.append(input);
          }
          input.value = this.token;
        }
      } else {
        url.searchParams.set('_ps', this.token);
        form.action = url.href;
      }
    }, true);

    let lastAnnounced = '';
    const announce = (): void => {
      const url = this.clean(new URL(window.location.href)).href;
      if (url !== lastAnnounced) {
        lastAnnounced = url;
        this.host?.postMessage({ type: 'typo3-preview-ready', url }, this.parentOrigin);
      }
    };
    navigation?.addEventListener('currententrychange', announce);
    for (const method of ['pushState', 'replaceState'] as const) {
      const original = window.history[method].bind(window.history);
      window.history[method] = (...args: Parameters<History['pushState']>): void => {
        original(...args);
        announce();
      };
    }
    window.addEventListener('pageshow', announce);
    window.addEventListener('popstate', announce);
    window.addEventListener('hashchange', announce);
    announce();
  }

  private clean(url: URL): URL {
    url.searchParams.delete('_ps');
    return url;
  }
}


const script = document.querySelector<HTMLScriptElement>('script[data-typo3-preview-navigation]');
if (script) {
  new PreviewNavigation(script.dataset.parent, JSON.parse(script.dataset.scope));
}
