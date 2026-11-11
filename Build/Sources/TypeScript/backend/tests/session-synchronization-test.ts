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

import { expect } from '@open-wc/testing';
import { stub, useFakeTimers, type SinonFakeTimers, type SinonStub } from 'sinon';
import { SessionSynchronization } from '@typo3/backend/session-synchronization.js';
import Notification from '@typo3/backend/notification.js';

const sourceOrigin = 'https://source.example.org';
const targetOrigin = 'https://target.example.com';
const endpoint = sourceOrigin + '/admin/session/synchronize/control';
const task = {
  flow: 'bound-flow',
  sourceOrigin,
  target: targetOrigin,
  url: targetOrigin + '/admin/session/synchronize/bridge',
  fields: { stage: 'bootstrap', code: 'opaque-target-code' },
  returnUrl: sourceOrigin + '/admin/',
};

describe('@typo3/backend/session-synchronization', () => {
  let clock: SinonFakeTimers;
  let fetchStub: SinonStub;
  let formSubmit: SinonStub;
  let notification: SinonStub;
  let focus: SinonStub;
  let frameTasks: number;
  let navigationAvailable: boolean;
  let frameWindow: Window;
  let controlCalls: URLSearchParams[];

  const initialize = (): void => {
    new SessionSynchronization({ origin: sourceOrigin, href: sourceOrigin + '/admin/' }).initialize({ endpoint });
  };

  const send = (origin: string, flow: string, result: string, sender: Window = frameWindow): void => {
    window.dispatchEvent(new MessageEvent('message', {
      origin,
      source: sender,
      data: { type: 'typo3-session-synchronization', flow, result },
    }));
  };

  beforeEach((): void => {
    clock = useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
    frameTasks = 0;
    navigationAvailable = false;
    frameWindow = null;
    controlCalls = [];
    notification = stub(Notification, 'warning');
    focus = stub(document, 'hasFocus').returns(true);
    formSubmit = stub(HTMLFormElement.prototype, 'submit').callsFake(function (this: HTMLFormElement): void {
      if (this.target !== '_self') {
        frameWindow = document.querySelector<HTMLIFrameElement>('iframe[name="' + this.target + '"]').contentWindow;
      }
    });
    fetchStub = stub(window, 'fetch').callsFake(async (_input: RequestInfo | URL, options?: RequestInit): Promise<Response> => {
      const fields = new URLSearchParams(options?.body as URLSearchParams);
      controlCalls.push(fields);
      let data: unknown = null;
      if (fields.get('action') === 'status') {
        data = { enabled: true, pending: true, results: [{ origin: targetOrigin, status: 'navigation-required' }] };
      } else if (fields.get('action') === 'next' && fields.get('mode') === 'frame') {
        data = frameTasks++ === 0 ? task : null;
      } else if (fields.get('action') === 'next' && fields.get('mode') === 'navigation') {
        data = navigationAvailable ? { ...task, flow: 'navigation-flow' } : null;
        navigationAvailable = false;
      }
      return { ok: true, json: async (): Promise<unknown> => data } as Response;
    });
  });

  afterEach((): void => {
    clock.restore();
    fetchStub.restore();
    formSubmit.restore();
    notification.restore();
    focus.restore();
    document.querySelectorAll('iframe[name^="typo3-session-sync-"], form[hidden], #typo3-contentIframe').forEach((element) => element.remove());
  });

  it('starts after initialization without waiting for another domain', async () => {
    initialize();

    expect(fetchStub.callCount).to.equal(0);
    expect(formSubmit.callCount).to.equal(0);

    await clock.tickAsync(0);

    expect(formSubmit.callCount).to.equal(1);
    expect(document.querySelector('iframe[name^="typo3-session-sync-"]')).not.to.equal(null);
    send(sourceOrigin, task.flow, 'synchronized');
    await clock.tickAsync(0);
  });

  it('ignores messages from another window, another origin, or another flow', async () => {
    initialize();
    await clock.tickAsync(0);

    send(sourceOrigin, task.flow, 'synchronized', window);
    send(targetOrigin, task.flow, 'synchronized');
    send('https://attacker.example.net', task.flow, 'synchronized');
    send(sourceOrigin, 'another-flow', 'synchronized');
    await clock.tickAsync(0);

    expect(frameTasks).to.equal(1);
    expect(document.querySelector('iframe[name^="typo3-session-sync-"]')).not.to.equal(null);

    send(sourceOrigin, task.flow, 'synchronized');
    await clock.tickAsync(0);

    expect(frameTasks).to.equal(2);
    expect(document.querySelector('iframe[name^="typo3-session-sync-"]')).to.equal(null);
    expect(document.querySelector('form[hidden]')).to.equal(null);
  });

  it('cleans up an unreachable frame and records a failure without navigating', async () => {
    navigationAvailable = false;
    initialize();
    await clock.tickAsync(0);
    await clock.tickAsync(2000);

    expect(document.querySelector('iframe[name^="typo3-session-sync-"]')).to.equal(null);
    expect(document.querySelector('form[hidden]')).to.equal(null);
    expect(controlCalls.some((fields) => fields.get('action') === 'failed' && fields.get('flow') === task.flow && fields.get('result') === 'failed')).to.equal(true);
    expect(formSubmit.callCount).to.equal(1);
  });

  it('uses navigation only after a message from the bound target frame requires it', async () => {
    navigationAvailable = true;
    initialize();
    await clock.tickAsync(0);
    send(targetOrigin, task.flow, 'navigation-required');
    await clock.tickAsync(0);

    expect(controlCalls.some((fields) => fields.get('action') === 'failed' && fields.get('result') === 'navigation-required')).to.equal(true);
    expect(formSubmit.callCount).to.equal(2);
    expect((formSubmit.lastCall.thisValue as HTMLFormElement).target).to.equal('_self');
  });

  it('preserves work in the module iframe until completion is explicitly requested', async () => {
    const moduleFrame = document.createElement('iframe');
    moduleFrame.id = 'typo3-contentIframe';
    document.body.append(moduleFrame);
    navigationAvailable = true;
    initialize();
    await clock.tickAsync(0);

    moduleFrame.contentWindow.focus();
    expect(document.activeElement).to.equal(moduleFrame);
    send(targetOrigin, task.flow, 'navigation-required');
    await clock.tickAsync(0);

    expect(formSubmit.callCount).to.equal(1);
    expect(controlCalls.some((fields) => fields.get('action') === 'next' && fields.get('mode') === 'navigation')).to.equal(false);
    expect(notification.calledOnce).to.equal(true);

    const complete = notification.firstCall.args[3][0].action;
    await complete.execute(document.createElement('button'));

    expect(controlCalls.some((fields) => fields.get('action') === 'retry' && fields.get('mode') === 'navigation')).to.equal(true);
    expect(formSubmit.callCount).to.equal(2);
    expect((formSubmit.lastCall.thisValue as HTMLFormElement).target).to.equal('_self');
  });
});
