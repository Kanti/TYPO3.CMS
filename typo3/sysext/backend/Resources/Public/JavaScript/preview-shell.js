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
import n from"@typo3/backend/preview-controller.js";const r=document.querySelector("#typo3-preview-frame"),t=document.querySelector("#typo3-preview-live"),o=new n(r.contentWindow,r.src,r,e=>{t.href=e.href,document.querySelector("#typo3-preview-url").textContent=e.href});document.querySelector("#typo3-preview-refresh").addEventListener("click",()=>o.refresh()),t.addEventListener("click",e=>{e.preventDefault(),o.dispose(),window.location.assign(t.href)});
