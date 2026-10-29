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

import PreviewController from '@typo3/backend/preview-controller';

const frame = document.querySelector<HTMLIFrameElement>('#typo3-preview-frame');
const live = document.querySelector<HTMLAnchorElement>('#typo3-preview-live');
const preview = new PreviewController(frame.contentWindow, frame.src, frame, url => {
  live.href = url.href;
  document.querySelector('#typo3-preview-url').textContent = url.href;
});
document.querySelector('#typo3-preview-refresh').addEventListener('click', () => preview.refresh());
live.addEventListener('click', (event: MouseEvent) => {
  event.preventDefault();
  preview.dispose();
  window.location.assign(live.href);
});
