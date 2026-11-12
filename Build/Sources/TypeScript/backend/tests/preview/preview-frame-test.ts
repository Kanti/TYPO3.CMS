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

import PreviewFrame from '@typo3/backend/preview/preview-frame.js';
import { expect, waitUntil } from '@open-wc/testing';
import { stub, useFakeTimers, type SinonStub } from 'sinon';
import type { } from 'mocha';

type Renewal = { previewToken: string, url: string, page: string };

type Backend = {
  fetch: SinonStub,
  renewals: Renewal[],
  adminPanelRequests: Array<{ url: string, fields: Array<[string, string]> }>,
};

/**
 * Answers the requests of the backend page as the backend does: the token endpoint signs
 * the requested address with the next token and names the hosts of the preview, the
 * endpoints of the admin panel store its settings.
 */
function stubBackend(hosts: string[] = ['site.example']): Backend {
  const backend: Backend = { fetch: null, renewals: [], adminPanelRequests: [] };
  backend.fetch = stub(window, 'fetch').callsFake(async (input: RequestInfo | URL): Promise<Response> => {
    const request = input as Request;
    const path = new URL(request.url).pathname;
    const fields = request.method === 'POST' ? [...(await request.formData()).entries()] as Array<[string, string]> : [];
    if (path === '/renew') {
      const renewal = Object.fromEntries(fields) as Renewal;
      backend.renewals.push(renewal);
      const signed = new URL(renewal.url);
      signed.searchParams.set('__t3ct', 'token-' + backend.renewals.length);
      return new Response(JSON.stringify({ url: signed.href, expiresIn: 900, hosts }), { headers: { 'Content-Type': 'application/json' } });
    }
    backend.adminPanelRequests.push({ url: path, fields });
    return new Response('{}', { headers: { 'Content-Type': 'application/json' } });
  });
  return backend;
}

/**
 * The frame loads a site the test cannot reach. A second frame on the origin of the test
 * stands in for the page in it, so the test sees what the backend page posts to the page.
 */
function standInForThePageIn(frame: HTMLIFrameElement): Window {
  const standIn = document.createElement('iframe');
  standIn.dataset.standIn = '';
  document.body.append(standIn);
  Object.defineProperty(frame, 'contentWindow', { value: standIn.contentWindow, configurable: true });
  return standIn.contentWindow;
}

function send(frame: HTMLIFrameElement, data: Record<string, unknown>, source: Window = frame.contentWindow, origin: string = 'https://site.example'): void {
  window.dispatchEvent(new MessageEvent('message', { data, source, origin }));
}

describe('@typo3/backend/preview/preview-frame and the admin panel in its frame', (): void => {
  let frame: HTMLIFrameElement;
  let preview: PreviewFrame;
  let backend: Backend;

  beforeEach((): void => {
    window.TYPO3 = window.TYPO3 || {} as typeof window.TYPO3;
    window.TYPO3.settings = { ajaxUrls: { adminPanel_saveForm: '/typo3/ajax/adminpanel/form/save', adminPanel_toggle: '/typo3/ajax/adminpanel/toggleActiveState' } } as unknown as typeof window.TYPO3.settings;
    backend = stubBackend();
    frame = document.createElement('iframe');
    document.body.append(frame);
    preview = new PreviewFrame(frame, { url: 'https://site.example/news?__t3ct=token', renewUrl: '/renew', page: 1, hosts: ['site.example'], expiresIn: 900 });
  });

  afterEach((): void => {
    preview.dispose();
    frame.remove();
    backend.fetch.restore();
  });

  it('stores the settings of the admin panel with the backend session and loads the page again with a fresh token', async (): Promise<void> => {
    send(frame, { type: 'typo3:preview:admin-panel', url: 'https://site.example/news', action: 'save', fields: [['TSFE_ADMIN_PANEL[preview_showHiddenRecords]', '1'], ['invalid', 1]] });

    await waitUntil((): boolean => frame.getAttribute('src') === 'https://site.example/news?__t3ct=token-1');
    expect(backend.adminPanelRequests).to.deep.equal([
      { url: '/typo3/ajax/adminpanel/form/save', fields: [['TSFE_ADMIN_PANEL[preview_showHiddenRecords]', '1']] },
    ]);
    expect(backend.renewals).to.deep.equal([{ previewToken: 'token', url: 'https://site.example/news', page: '1' }]);
  });

  it('toggles the admin panel with the backend session and loads the page again with a fresh token', async (): Promise<void> => {
    send(frame, { type: 'typo3:preview:admin-panel', url: 'https://site.example/news', action: 'toggle', fields: [] });

    await waitUntil((): boolean => frame.getAttribute('src') === 'https://site.example/news?__t3ct=token-1');
    expect(backend.adminPanelRequests).to.deep.equal([{ url: '/typo3/ajax/adminpanel/toggleActiveState', fields: [] }]);
  });

  it('ignores admin panel messages that do not come from the frame of a preview host', async (): Promise<void> => {
    send(frame, { type: 'typo3:preview:admin-panel', url: 'https://site.example/news', action: 'toggle', fields: [] }, window);
    send(frame, { type: 'typo3:preview:admin-panel', url: 'https://outside.example/', action: 'toggle', fields: [] }, frame.contentWindow, 'https://outside.example');
    send(frame, { type: 'typo3:preview:admin-panel', url: 'https://site.example/news', action: 'delete', fields: [] });

    await new Promise((resolve): number => window.setTimeout(resolve, 50));
    expect(backend.fetch.called).to.equal(false);
    expect(frame.getAttribute('src')).to.equal('https://site.example/news?__t3ct=token');
  });
});

describe('@typo3/backend/preview/preview-frame and its token', (): void => {
  let frame: HTMLIFrameElement;
  let backend: Backend;

  beforeEach((): void => {
    backend = stubBackend();
    frame = document.createElement('iframe');
    document.body.append(frame);
  });

  afterEach((): void => {
    frame.remove();
    document.querySelectorAll('iframe[data-stand-in]').forEach((standIn: Element): void => standIn.remove());
    backend.fetch.restore();
  });

  it('renews the token before it expires', async (): Promise<void> => {
    const clock = useFakeTimers({ shouldAdvanceTime: true });
    const preview = new PreviewFrame(frame, { url: 'https://site.example/?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 90 });
    try {
      await clock.tickAsync(31_000);
      await waitUntil((): boolean => backend.renewals.length === 1);
      expect(backend.renewals).to.deep.equal([{ previewToken: 'token', url: 'https://site.example/', page: '3' }]);
    } finally {
      preview.dispose();
      clock.restore();
    }
  });

  /**
   * The page posts its address whenever it is shown. A renewal the backend page posted
   * while the frame was loading the next page reached the page that was about to go,
   * so the backend page hands its token to every page that reports its address.
   */
  it('hands its current token to a page that reports its address', async (): Promise<void> => {
    const clock = useFakeTimers({ shouldAdvanceTime: true });
    const page = standInForThePageIn(frame);
    // Typed loosely: the typings offer only the overload with options for a stub of postMessage
    const postMessage = stub(page, 'postMessage') as unknown as SinonStub;
    const preview = new PreviewFrame(frame, { url: 'https://site.example/one?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 90 });
    try {
      await clock.tickAsync(31_000);
      await waitUntil((): boolean => postMessage.calledWith({ type: 'typo3:preview:token', token: 'token-1' }, 'https://site.example'));
      postMessage.resetHistory();

      send(frame, { type: 'typo3:preview:location', url: 'https://site.example/two', page: 4, expires: 1 });

      expect(postMessage.calledOnceWith({ type: 'typo3:preview:token', token: 'token-1' }, 'https://site.example')).to.equal(true);
    } finally {
      preview.dispose();
      clock.restore();
    }
  });

  it('takes the hosts of the preview from every renewal', async (): Promise<void> => {
    backend.fetch.restore();
    backend = stubBackend(['site.example', 'other.example']);
    const open = stub(window, 'open');
    const preview = new PreviewFrame(frame, { url: 'https://site.example/?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 900 });
    const navigate = (): void => send(frame, { type: 'typo3:preview:navigate', url: 'https://other.example/page' });
    try {
      navigate();
      expect(open.calledOnce).to.equal(true);

      preview.reload();
      await waitUntil((): boolean => frame.getAttribute('src') === 'https://site.example/?__t3ct=token-1');
      navigate();

      await waitUntil((): boolean => backend.renewals.length === 2);
      expect(backend.renewals[1]).to.deep.equal({ previewToken: 'token-1', url: 'https://other.example/page', page: '3' });
      expect(open.calledOnce).to.equal(true);
    } finally {
      preview.dispose();
      open.restore();
    }
  });

  /**
   * A page the editor returns to through the history may carry a token that expired.
   * The page is loaded again with a fresh token, and once it was shown there, a later
   * expiry at the same address is recovered the same way.
   */
  it('loads an expired address again each time the page was shown there in between', async (): Promise<void> => {
    const failure = stub();
    const preview = new PreviewFrame(frame, { url: 'https://site.example/one?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 900 }, { onFailure: failure });
    const expired = (): void => send(frame, { type: 'typo3:preview:expired', url: 'https://site.example/one?__t3ct=token', reason: 'expired' });
    try {
      expired();
      await waitUntil((): boolean => frame.getAttribute('src') === 'https://site.example/one?__t3ct=token-1');
      send(frame, { type: 'typo3:preview:location', url: 'https://site.example/one', page: 3, expires: 1 });

      expired();

      await waitUntil((): boolean => frame.getAttribute('src') === 'https://site.example/one?__t3ct=token-2');
      expect(failure.called).to.equal(false);
    } finally {
      preview.dispose();
    }
  });

  /**
   * A token the site keeps refusing at an address would load the page again and again;
   * a second expiry before the page was shown there stops the preview with its notice.
   */
  it('stops when an address expires again before the page was shown there', async (): Promise<void> => {
    const failure = stub();
    const preview = new PreviewFrame(frame, { url: 'https://site.example/one?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 900 }, { onFailure: failure });
    const expired = (): void => send(frame, { type: 'typo3:preview:expired', url: 'https://site.example/one?__t3ct=token', reason: 'expired' });
    try {
      expired();
      await waitUntil((): boolean => frame.getAttribute('src') === 'https://site.example/one?__t3ct=token-1');

      expired();

      expect(failure.calledOnce).to.equal(true);
      expect(backend.renewals.length).to.equal(1);
    } finally {
      preview.dispose();
    }
  });

  it('loads an address outside the sites of the installation as it is', (): void => {
    const preview = new PreviewFrame(frame, { url: 'https://headless.example/page?a=1', renewUrl: '', page: 3, hosts: [], expiresIn: 0 });

    expect(frame.getAttribute('src')).to.equal('https://headless.example/page?a=1');
    preview.dispose();
  });

  it('reloads an address outside the sites of the installation without asking the backend for a token', (): void => {
    const preview = new PreviewFrame(frame, { url: 'https://headless.example/page', renewUrl: '', page: 3, hosts: [], expiresIn: 0 });
    frame.removeAttribute('src');

    preview.reload();

    expect(frame.getAttribute('src')).to.equal('https://headless.example/page');
    expect(backend.fetch.called).to.equal(false);
    preview.dispose();
  });

  it('never renews a token for an address outside the sites of the installation', async (): Promise<void> => {
    const clock = useFakeTimers({ shouldAdvanceTime: true });
    const preview = new PreviewFrame(frame, { url: 'https://headless.example/page', renewUrl: '', page: 3, hosts: [], expiresIn: 0 });
    try {
      await clock.tickAsync(20 * 60_000);
      expect(backend.fetch.called).to.equal(false);
    } finally {
      preview.dispose();
      clock.restore();
    }
  });

  it('loads the address with its preview token into the frame', (): void => {
    const preview = new PreviewFrame(frame, { url: 'https://site.example/?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 900 });

    expect(frame.getAttribute('src')).to.equal('https://site.example/?__t3ct=token');
    preview.dispose();
  });
});

describe('@typo3/backend/preview/preview-frame and the query of an address', (): void => {
  const query = 'utm.source=x&id=1&id=2&flag&q=a+b&a[]=1&a%20b=1&sig=ab%2bcd&empty=&&last';
  const cases: Array<[string, string, string]> = [
    ['the token at the start', `https://site.example/p?__t3ct=jwt&${query}#top`, `https://site.example/p?${query}#top`],
    ['the token in the middle', 'https://site.example/p?utm.source=x&__t3ct=jwt&flag&q=a+b', 'https://site.example/p?utm.source=x&flag&q=a+b'],
    ['the token at the end', `https://site.example/p?${query}&__t3ct=jwt`, `https://site.example/p?${query}`],
    ['a token without value', `https://site.example/p?${query}&__t3ct`, `https://site.example/p?${query}`],
    ['an encoded token name', `https://site.example/p?%5F%5Ft3ct=jwt&${query}`, `https://site.example/p?${query}`],
    ['the token twice', `https://site.example/p?__t3ct=a&${query}&__t3ct=b`, `https://site.example/p?${query}`],
    ['only the token', 'https://site.example/p?__t3ct=jwt#top', 'https://site.example/p#top'],
    ['no token', `https://site.example/p?${query}#top`, `https://site.example/p?${query}#top`],
    ['similar names', 'https://site.example/p?__t3ct_x=1&x__t3ct=2&__t3ct[]=3', 'https://site.example/p?__t3ct_x=1&x__t3ct=2&__t3ct[]=3'],
  ];

  cases.forEach(([name, address, expected]): void => {
    it(`removes only the token from an address with ${name}`, (): void => {
      expect(PreviewFrame.withoutToken(new URL(address)).href).to.equal(new URL(expected).href);
    });
  });
});
