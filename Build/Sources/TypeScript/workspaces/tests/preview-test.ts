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

import { expect, waitUntil } from '@open-wc/testing';
import { stub, type SinonStub } from 'sinon';
import type { } from 'mocha';

type Renewal = { previewToken: string, url: string, page: string };

const stageButtons = '<input type="button" value="Publish" data-action="publish"><input type="button" value="Discard" data-action="discard">';

function json(data: unknown): Response {
  return new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json' } });
}

/**
 * Answers the requests of the preview module as the backend does: the workspace
 * dispatcher returns a result for every method it was asked for, the stage buttons
 * included, and the token endpoint signs the requested address with the next token.
 */
function stubBackend(renewals: Renewal[]): SinonStub {
  return stub(window, 'fetch').callsFake(async (input: RequestInfo | URL): Promise<Response> => {
    const request = input as Request;
    if (new URL(request.url).pathname === '/renew') {
      const renewal = Object.fromEntries((await request.formData()).entries()) as Renewal;
      renewals.push(renewal);
      const signed = new URL(renewal.url);
      signed.searchParams.set('__t3ct', 'token-' + renewals.length);
      return json({ url: signed.href, expiresIn: 900, hosts: ['site.example'] });
    }
    const body: unknown = await request.json();
    const calls = (Array.isArray(body) ? body : [body]) as Array<{ method: string }>;
    return json(calls.map(({ method }) => ({ method, result: method === 'updateStageChangeButtons' ? stageButtons : true })));
  });
}

function renderModule(): void {
  const container = document.createElement('div');
  container.dataset.workspacePreviewModule = '';
  container.innerHTML = `
    <div class="t3js-workspace-topbar">
      <div class="t3js-stage-slider-container"><input type="range" class="t3js-stage-slider" min="0" max="100" value="100"></div>
      <div class="t3js-stage-buttons"></div>
      <div class="t3js-preview-mode"><span class="t3js-active-preview-mode" data-active-preview-mode="slider"></span></div>
    </div>
    <div class="t3js-workspace-preview">
      <iframe class="t3js-workspace-view-live"></iframe>
      <iframe class="t3js-workspace-view-workspace"></iframe>
    </div>
  `;
  const settings = { renewUrl: '/renew', page: 1, hosts: ['site.example'], expiresIn: 900 };
  container.querySelector<HTMLIFrameElement>('.t3js-workspace-view-live').dataset.previewSettings = JSON.stringify({ ...settings, url: 'https://site.example/one?__t3ct=live' });
  container.querySelector<HTMLIFrameElement>('.t3js-workspace-view-workspace').dataset.previewSettings = JSON.stringify({ ...settings, url: 'https://site.example/one?__t3ct=first' });
  document.body.append(container);
}

/**
 * Collects every address the frame is told to load, also the same address again,
 * which loads the frame anew as well.
 */
function recordLoads(frame: HTMLIFrameElement): { loads: string[], observer: MutationObserver } {
  const loads: string[] = [];
  const observer = new MutationObserver((): void => {
    loads.push(frame.getAttribute('src'));
  });
  observer.observe(frame, { attributes: true, attributeFilter: ['src'] });
  return { loads, observer };
}

describe('@typo3/workspaces/preview', (): void => {
  const nativeRequestAnimationFrame = window.requestAnimationFrame;
  const renewals: Renewal[] = [];
  let backend: SinonStub;
  let workspaceFrame: HTMLIFrameElement;

  /**
   * The page in the frame reports every address it shows, also those the editor
   * reached by following links of the site.
   */
  const followLinkInFrame = (url: string, page: number): void => {
    window.dispatchEvent(new MessageEvent('message', {
      data: { type: 'typo3:preview:location', url, page },
      source: workspaceFrame.contentWindow,
      origin: new URL(url).origin,
    }));
  };

  const confirm = async (action: string): Promise<void> => {
    const shown = new Promise<Element>((resolve): void => {
      document.addEventListener('typo3-modal-shown', (event: Event): void => resolve(event.target as Element), { once: true });
    });
    document.querySelector<HTMLInputElement>(`.t3js-stage-buttons [data-action="${action}"]`).click();
    (await shown).querySelector<HTMLButtonElement>('button[name="ok"]').click();
  };

  before(async (): Promise<void> => {
    // The test page runs in the background, animation frames never fire there
    window.requestAnimationFrame = (callback: FrameRequestCallback): number => window.setTimeout(() => callback(performance.now()), 0);
    window.TYPO3 = window.TYPO3 || {} as typeof window.TYPO3;
    window.TYPO3.settings = { ajaxUrls: { workspace_preview: '/typo3/ajax/workspaces/preview' }, Workspaces: { id: 1 } } as unknown as typeof window.TYPO3.settings;
    backend = stubBackend(renewals);
    renderModule();
    workspaceFrame = document.querySelector<HTMLIFrameElement>('.t3js-workspace-view-workspace');
    await import('@typo3/workspaces/preview.js');
    await waitUntil((): boolean => document.querySelector('.t3js-stage-buttons [data-action="publish"]') !== null);
  });

  after((): void => {
    backend.restore();
    window.requestAnimationFrame = nativeRequestAnimationFrame;
    document.querySelector('[data-workspace-preview-module]').remove();
  });

  beforeEach((): void => {
    renewals.length = 0;
  });

  afterEach((): void => {
    // The dialog close event does not fire in the background, so the modal stays in the document
    document.querySelectorAll('typo3-backend-modal').forEach((modal: Element): void => modal.remove());
  });

  /**
   * The editor may follow links in the workspace frame for longer than the token the
   * frame was opened with lives. Publishing or discarding loads the frame again at the
   * address it shows with a fresh token, not at the address and token it was opened with.
   */
  it('loads the workspace frame at the address shown in it with a fresh token after publishing', async (): Promise<void> => {
    followLinkInFrame('https://site.example/two', 2);
    const { loads, observer } = recordLoads(workspaceFrame);
    try {
      await confirm('publish');

      await waitUntil((): boolean => loads.length > 0, 'The workspace frame was not loaded again');
      expect(loads).to.deep.equal(['https://site.example/two?__t3ct=token-1']);
      expect(renewals.map(({ url, page }) => ({ url, page }))).to.deep.equal([{ url: 'https://site.example/two', page: '2' }]);
    } finally {
      observer.disconnect();
    }
  });

  it('loads the workspace frame at the address shown in it with a fresh token after discarding', async (): Promise<void> => {
    followLinkInFrame('https://site.example/three', 3);
    const { loads, observer } = recordLoads(workspaceFrame);
    try {
      await confirm('discard');

      await waitUntil((): boolean => loads.length > 0, 'The workspace frame was not loaded again');
      expect(loads).to.deep.equal(['https://site.example/three?__t3ct=token-1']);
      expect(renewals.map(({ url, page }) => ({ url, page }))).to.deep.equal([{ url: 'https://site.example/three', page: '3' }]);
    } finally {
      observer.disconnect();
    }
  });
});
