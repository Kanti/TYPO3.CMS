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

/**
 * Module: @typo3/frontend/preview-navigation
 *
 * Added to pages shown with a preview token. Links rendered by TYPO3 already carry the
 * token; this is the fallback for navigations started by scripts, and it reports the
 * address of the frame to the backend page around it.
 */

const parameter = '__t3ct';
const script = document.querySelector<HTMLScriptElement>('script[data-preview-backend]');

/**
 * The query is handled as text, as in @typo3/backend/preview/preview-frame: only pairs
 * named like the token are touched, all other pairs keep their bytes. This module runs
 * on the site without the import map of the backend, so it carries its own copy.
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

function withoutToken(url: URL): URL {
  const clean = new URL(url);
  const pairs = queryPairs(url);
  const kept = pairs.filter((pair: string): boolean => !isTokenPair(pair));
  if (kept.length !== pairs.length) {
    clean.search = kept.length === 0 ? '' : '?' + kept.join('&');
  }
  return clean;
}

function parseUrl(address: string, base?: string): URL | null {
  try {
    return new URL(address, base);
  } catch {
    return null;
  }
}

function withToken(url: URL, value: string): URL {
  const target = withoutToken(url);
  const query = target.search.slice(1);
  target.search = '?' + (query === '' ? '' : query + '&') + parameter + '=' + encodeURIComponent(value);
  return target;
}

if (script && window.parent !== window) {
  const backend = script.dataset.previewBackend;
  const hosts: string[] = JSON.parse(script.dataset.previewHosts);
  let token = readToken(new URL(window.location.href));

  function isPreviewHost(url: URL): boolean {
    if (!['http:', 'https:'].includes(url.protocol)) {
      return false;
    }
    return hosts.includes(url.host.toLowerCase());
  }

  function post(message: Record<string, unknown>): void {
    window.parent.postMessage(message, backend);
  }

  post({
    type: 'typo3:preview:location',
    url: withoutToken(new URL(window.location.href)).href,
    page: Number(script.dataset.previewPage),
    expires: Number(script.dataset.previewExpires),
  });

  window.addEventListener('message', (event: MessageEvent): void => {
    if (event.source !== window.parent) {
      return;
    }
    if (event.origin !== backend) {
      return;
    }
    if (event.data?.type !== 'typo3:preview:token') {
      return;
    }
    if (typeof event.data.token !== 'string') {
      return;
    }
    if (event.data.token === token) {
      return;
    }
    const previous = token;
    token = event.data.token;
    window.history.replaceState(window.history.state, '', withToken(new URL(window.location.href), token));
    document.querySelectorAll('a[href], area[href]').forEach((link: Element): void => {
      // A link of an inline SVG has no address to rewrite
      if (!(link instanceof HTMLAnchorElement) && !(link instanceof HTMLAreaElement)) {
        return;
      }
      const url = parseUrl(link.href);
      if (url === null) {
        return;
      }
      if (readToken(url) !== previous) {
        return;
      }
      link.href = withToken(url, token).href;
    });
    document.querySelectorAll<HTMLFormElement>('form[action]').forEach((form: HTMLFormElement): void => {
      const action = parseUrl(form.action, document.baseURI);
      if (action === null) {
        return;
      }
      if (readToken(action) !== previous) {
        return;
      }
      form.action = withToken(action, token).href;
    });
    document.querySelectorAll<HTMLInputElement>(`input[type="hidden"][name="${parameter}"]`).forEach((field: HTMLInputElement): void => {
      if (field.value === previous) {
        field.value = token;
      }
    });
  });

  const navigation = 'navigation' in window ? window.navigation : null;
  navigation?.addEventListener('navigate', (event: NavigateEvent): void => {
    if (!event.cancelable) {
      return;
    }
    if (event.hashChange) {
      return;
    }
    if (event.downloadRequest !== null) {
      return;
    }
    if (event.destination.sameDocument) {
      return;
    }
    // A form sent via HTTP POST goes as it is: f:form puts the token into its action, any
    // other form shows the page without the preview
    if (event.formData !== null) {
      return;
    }
    const url = new URL(event.destination.url);
    const destinationToken = readToken(url);
    // A token this page does not hold is a fresh one of the page around the frame: on the
    // origin of this page, browsers tell this page about the addresses that page loads
    if (destinationToken !== null && destinationToken !== token) {
      return;
    }
    if (url.origin !== window.location.origin) {
      // The page around the frame decides: a host of the preview with a fresh token, any other in a new tab
      event.preventDefault();
      post({ type: 'typo3:preview:navigate', url: withoutToken(url).href });
      return;
    }
    if (!isPreviewHost(url)) {
      return;
    }
    if (destinationToken === token) {
      return;
    }
    if (!event.canIntercept) {
      return;
    }
    event.preventDefault();
    navigation.navigate(withToken(url, token).href, { history: event.navigationType === 'replace' ? 'replace' : 'auto' });
  });
}
