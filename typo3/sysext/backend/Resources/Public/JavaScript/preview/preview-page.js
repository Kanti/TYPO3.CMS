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
import s from"@typo3/core/document-service.js";import c from"@typo3/backend/preview/preview-frame.js";s.ready().then(()=>{const e=document.querySelector("[data-preview-settings]"),n=e?.querySelector("[data-preview-frame]");if(!e||!n)return;const t=e.querySelector("[data-preview-notice]"),a=JSON.parse(e.dataset.previewSettings);new c(n,a,{onLocation:(o,i)=>{t.hidden=!0;const r=new URL(window.location.href);r.searchParams.set("url",o.href),r.searchParams.set("page",String(i)),window.history.replaceState(window.history.state,"",r)},onFailure:()=>{t.textContent=t.dataset.noticeFailed,t.hidden=!1}})});
