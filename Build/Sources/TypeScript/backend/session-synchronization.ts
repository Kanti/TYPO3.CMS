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

import ImmediateAction from '@typo3/backend/action-button/immediate-action';
import Notification from '@typo3/backend/notification';
import labels from '~labels/backend.session_synchronization';

type Result = 'pending' | 'synchronized' | 'skipped' | 'failed' | 'navigation-required';

interface Configuration {
  endpoint: string;
}

interface Status {
  enabled: boolean;
  pending: boolean;
  results: Array<{origin: string, status: Result}>;
}

interface Task {
  flow: string;
  target: string;
  url: string;
  fields: Record<string, string>;
  sourceOrigin: string;
  returnUrl: string;
}

interface BridgeMessage {
  type: string;
  flow: string;
  result: Result;
}

/**
 * Synchronizes domain cookies after the local backend is available.
 */
export class SessionSynchronization {
  private endpoint: URL;
  private running: boolean = false;
  private userStartedWorking: boolean = false;

  constructor(private readonly location: Pick<Location, 'href' | 'origin'> = window.location) {}

  public initialize(configuration: Configuration): void {
    this.endpoint = new URL(configuration.endpoint, this.location.href);
    if (this.endpoint.origin !== this.location.origin || this.endpoint.protocol !== 'https:') {
      return;
    }
    const interaction = (event: Event): void => {
      if (event.isTrusted) {
        this.userStartedWorking = true;
      }
    };
    window.addEventListener('pointerdown', interaction, true);
    window.addEventListener('keydown', interaction, true);
    const watchedDocuments = new WeakSet<Document>();
    const watchModule = (): void => {
      const frame = document.querySelector<HTMLIFrameElement>('#typo3-contentIframe');
      try {
        const frameDocument = frame?.contentDocument;
        if (frameDocument && !watchedDocuments.has(frameDocument)) {
          watchedDocuments.add(frameDocument);
          frameDocument.addEventListener('pointerdown', interaction, true);
          frameDocument.addEventListener('keydown', interaction, true);
        }
      } catch {
        // Focus tracking also protects modules hosted on another origin.
      }
    };
    document.addEventListener('typo3-module-loaded', watchModule);
    watchModule();
    window.addEventListener('blur', (): void => {
      window.setTimeout(() => {
        if (this.moduleFrameHasFocus()) {
          this.userStartedWorking = true;
        }
      }, 0);
    });
    window.setTimeout((): void => {
      void this.run();
    }, 0);
  }

  private async run(allowNavigation: boolean = false): Promise<void> {
    if (this.running) {
      return;
    }
    this.running = true;
    try {
      const initial = await this.control<Status>('status');
      if (!initial.enabled) {
        return;
      }
      const deadline = Date.now() + 10000;
      while (Date.now() < deadline) {
        const task = await this.control<Task | null>('next', { mode: 'frame' });
        if (!task) {
          break;
        }
        const result = await this.inFrame(task, Math.min(2000, deadline - Date.now()));
        if (result === 'failed' || result === 'navigation-required') {
          await this.control('failed', { flow: task.flow, result });
        }
      }
      if (this.mayNavigate(allowNavigation)) {
        const navigation = await this.control<Task | null>('next', { mode: 'navigation' });
        if (navigation) {
          if (this.mayNavigate(allowNavigation)) {
            this.submit(navigation);
            return;
          }
          await this.control('failed', { flow: navigation.flow, result: 'navigation-required' });
        }
      }
      this.showStatus(await this.control<Status>('status'));
    } catch {
      this.showFailure();
    } finally {
      this.running = false;
    }
  }

  private async control<T = unknown>(action: string, fields: Record<string, string> = {}): Promise<T> {
    const response = await fetch(this.endpoint.href, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'error',
      cache: 'no-store',
      signal: AbortSignal.timeout(5000),
      body: new URLSearchParams({ action, ...fields }),
    });
    if (!response.ok) {
      throw new Error('Session synchronization failed');
    }
    return response.json() as Promise<T>;
  }

  private inFrame(task: Task, timeout: number): Promise<Result> {
    this.validateTask(task);
    return new Promise((resolve) => {
      const frame = document.createElement('iframe');
      frame.name = 'typo3-session-sync-' + crypto.randomUUID();
      frame.hidden = true;
      frame.title = labels.get('title');
      frame.referrerPolicy = 'strict-origin';
      let form: HTMLFormElement = null;
      let timer: number = null;
      const finish = (result: Result): void => {
        window.clearTimeout(timer);
        window.removeEventListener('message', listener);
        form?.remove();
        frame.remove();
        resolve(result);
      };
      const listener = (event: MessageEvent<BridgeMessage>): void => {
        const data = event.data;
        if (event.source !== frame.contentWindow || !data || data.type !== 'typo3-session-synchronization' || data.flow !== task.flow) {
          return;
        }
        if (data.result === 'navigation-required' && (event.origin === task.target || event.origin === task.sourceOrigin)) {
          finish('navigation-required');
        } else if (event.origin === task.sourceOrigin && ['synchronized', 'skipped', 'failed'].includes(data.result)) {
          finish(data.result);
        }
      };
      window.addEventListener('message', listener);
      document.body.append(frame);
      timer = window.setTimeout(() => finish('failed'), Math.max(1, timeout));
      form = this.submit(task, frame.name);
    });
  }

  private submit(task: Task, target: string = '_self'): HTMLFormElement {
    this.validateTask(task);
    const form = document.createElement('form');
    form.method = 'post';
    form.action = task.url;
    form.target = target;
    form.hidden = true;
    for (const [name, value] of Object.entries(task.fields)) {
      if (typeof value !== 'string') {
        throw new Error('Invalid synchronization field');
      }
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.append(input);
    }
    document.body.append(form);
    HTMLFormElement.prototype.submit.call(form);
    return form;
  }

  private validateTask(task: Task): void {
    const url = new URL(task.url);
    if (url.protocol !== 'https:' || url.username || url.password || url.origin !== task.target || task.sourceOrigin !== this.location.origin || !task.flow) {
      throw new Error('Invalid synchronization task');
    }
  }

  private mayNavigate(explicit: boolean): boolean {
    return explicit || (!this.userStartedWorking && document.hasFocus() && !this.moduleFrameHasFocus());
  }

  private moduleFrameHasFocus(): boolean {
    return document.activeElement instanceof HTMLIFrameElement && !document.activeElement.hidden;
  }

  private showStatus(status: Status): void {
    const incomplete = status.results.filter((result) => result.status !== 'synchronized');
    if (incomplete.length === 0) {
      return;
    }
    const details = incomplete.map((result) => result.origin + ': ' + labels.get(result.status)).join('\n');
    Notification.warning(labels.get('title'), labels.get('incomplete') + '\n' + details, 0, [{
      label: labels.get('complete'),
      action: new ImmediateAction(async (): Promise<void> => {
        try {
          await this.control('retry', { mode: 'navigation' });
          await this.run(true);
        } catch {
          this.showFailure();
        }
      }),
    }]);
  }

  private showFailure(): void {
    Notification.warning(labels.get('title'), labels.get('unavailable'), 0, [{
      label: labels.get('retry'),
      action: new ImmediateAction(async (): Promise<void> => {
        await this.run();
      }),
    }]);
  }
}

export default new SessionSynchronization();
