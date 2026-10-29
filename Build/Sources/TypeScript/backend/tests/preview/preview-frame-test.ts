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
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import type { AjaxResponse } from '@typo3/core/ajax/ajax-response.js';
import { expect, waitUntil } from '@open-wc/testing';
import { stub, useFakeTimers, type SinonStub } from 'sinon';
import type { } from 'mocha';

describe('@typo3/backend/preview/preview-frame', (): void => {
  let frame: HTMLIFrameElement;
  let preview: PreviewFrame;
  let reload: SinonStub;
  let postStub: SinonStub;
  let getStub: SinonStub;

  function send(data: Record<string, unknown>, source: Window = frame.contentWindow, origin: string = 'https://site.example'): void {
    window.dispatchEvent(new MessageEvent('message', { data, source, origin }));
  }

  beforeEach((): void => {
    window.TYPO3 = window.TYPO3 || {} as typeof window.TYPO3;
    window.TYPO3.settings = { ajaxUrls: { adminPanel_saveForm: '/typo3/ajax/adminpanel/form/save', adminPanel_toggle: '/typo3/ajax/adminpanel/toggleActiveState' } } as unknown as typeof window.TYPO3.settings;
    postStub = stub(AjaxRequest.prototype, 'post').resolves({} as AjaxResponse);
    getStub = stub(AjaxRequest.prototype, 'get').resolves({} as AjaxResponse);
    frame = document.createElement('iframe');
    document.body.append(frame);
    preview = new PreviewFrame(frame, { url: 'https://site.example/?__t3ct=token', renewUrl: '/renew', page: 1, hosts: ['site.example'], expiresIn: 900 });
    reload = stub(preview, 'reload');
  });

  afterEach((): void => {
    preview.dispose();
    frame.remove();
    postStub.restore();
    getStub.restore();
  });

  it('stores the settings of the admin panel in the frame with the backend session and reloads the frame', async (): Promise<void> => {
    send({ type: 'typo3:preview:admin-panel', url: 'https://site.example/', action: 'save', fields: [['TSFE_ADMIN_PANEL[preview_showHiddenRecords]', '1'], ['invalid', 1]] });

    await waitUntil((): boolean => reload.called);
    const data: FormData = postStub.firstCall.args[0];
    expect([...data.entries()]).to.deep.equal([['TSFE_ADMIN_PANEL[preview_showHiddenRecords]', '1']]);
  });

  it('toggles the admin panel in the frame with the backend session and reloads the frame', async (): Promise<void> => {
    send({ type: 'typo3:preview:admin-panel', url: 'https://site.example/', action: 'toggle', fields: [] });

    await waitUntil((): boolean => reload.called);
    expect(getStub.calledOnce).to.equal(true);
  });

  it('ignores admin panel messages that do not come from the frame of a preview host', async (): Promise<void> => {
    send({ type: 'typo3:preview:admin-panel', url: 'https://site.example/', action: 'toggle', fields: [] }, window);
    send({ type: 'typo3:preview:admin-panel', url: 'https://outside.example/', action: 'toggle', fields: [] }, frame.contentWindow, 'https://outside.example');
    send({ type: 'typo3:preview:admin-panel', url: 'https://site.example/', action: 'delete', fields: [] });

    await new Promise((resolve): number => window.setTimeout(resolve, 50));
    expect(getStub.called).to.equal(false);
    expect(postStub.called).to.equal(false);
    expect(reload.called).to.equal(false);
  });
});

describe('@typo3/backend/preview/preview-frame and its token', (): void => {
  let frame: HTMLIFrameElement;
  let postStub: SinonStub;

  beforeEach((): void => {
    postStub = stub(AjaxRequest.prototype, 'post').resolves({
      resolve: (): Promise<unknown> => Promise.resolve({ url: 'https://site.example/?__t3ct=renewed', expiresIn: 900 }),
    } as unknown as AjaxResponse);
    frame = document.createElement('iframe');
    document.body.append(frame);
  });

  afterEach((): void => {
    frame.remove();
    postStub.restore();
  });

  it('renews the token before it expires', async (): Promise<void> => {
    const clock = useFakeTimers({ shouldAdvanceTime: true });
    const preview = new PreviewFrame(frame, { url: 'https://site.example/?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 90 });
    try {
      await clock.tickAsync(31_000);
      expect(postStub.calledOnce).to.equal(true);
      expect(postStub.firstCall.args[0]).to.deep.equal({ previewToken: 'token', url: 'https://site.example/', page: 3 });
    } finally {
      preview.dispose();
      clock.restore();
    }
  });

  it('takes the hosts of the preview from every renewal', async (): Promise<void> => {
    postStub.resolves({
      resolve: (): Promise<unknown> => Promise.resolve({ url: 'https://site.example/?__t3ct=renewed', expiresIn: 900, hosts: ['site.example', 'other.example'] }),
    } as unknown as AjaxResponse);
    const open = stub(window, 'open');
    const preview = new PreviewFrame(frame, { url: 'https://site.example/?__t3ct=token', renewUrl: '/renew', page: 3, hosts: ['site.example'], expiresIn: 900 });
    function navigate(): void {
      window.dispatchEvent(new MessageEvent('message', {
        data: { type: 'typo3:preview:navigate', url: 'https://other.example/page' },
        source: frame.contentWindow,
        origin: 'https://site.example',
      }));
    }
    try {
      navigate();
      expect(open.calledOnce).to.equal(true);

      preview.reload();
      await waitUntil((): boolean => frame.getAttribute('src') === 'https://site.example/?__t3ct=renewed');
      navigate();

      await waitUntil((): boolean => postStub.calledTwice);
      expect(postStub.secondCall.args[0]).to.deep.equal({ previewToken: 'renewed', url: 'https://other.example/page', page: 3 });
      expect(open.calledOnce).to.equal(true);
    } finally {
      preview.dispose();
      open.restore();
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
    expect(postStub.called).to.equal(false);
    preview.dispose();
  });

  it('never renews a token for an address outside the sites of the installation', async (): Promise<void> => {
    const clock = useFakeTimers({ shouldAdvanceTime: true });
    const preview = new PreviewFrame(frame, { url: 'https://headless.example/page', renewUrl: '', page: 3, hosts: [], expiresIn: 0 });
    try {
      await clock.tickAsync(20 * 60_000);
      expect(postStub.called).to.equal(false);
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
