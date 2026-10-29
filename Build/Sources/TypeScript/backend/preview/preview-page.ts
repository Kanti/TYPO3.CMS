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

import DocumentService from '@typo3/core/document-service';
import PreviewFrame, { type PreviewFrameSettings } from '@typo3/backend/preview/preview-frame';

/**
 * Module: @typo3/backend/preview/preview-page
 *
 * The preview page of the View button: mirrors the address of the frame into its
 * own address, so a reload signs a fresh token for the last visited page.
 */
DocumentService.ready().then((): void => {
  const container = document.querySelector<HTMLElement>('[data-preview-settings]');
  const frame = container?.querySelector<HTMLIFrameElement>('[data-preview-frame]');
  if (!container) {
    return;
  }
  if (!frame) {
    return;
  }
  const notice = container.querySelector<HTMLElement>('[data-preview-notice]');
  const settings: PreviewFrameSettings = JSON.parse(container.dataset.previewSettings);

  new PreviewFrame(frame, settings, {
    onLocation: (url: URL, page: number): void => {
      notice.hidden = true;
      const current = new URL(window.location.href);
      current.searchParams.set('url', url.href);
      current.searchParams.set('page', String(page));
      window.history.replaceState(window.history.state, '', current);
    },
    onFailure: (): void => {
      notice.textContent = notice.dataset.noticeFailed;
      notice.hidden = false;
    },
  });
});
