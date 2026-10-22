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
import s from"@typo3/core/event/regular-event.js";import d from"@typo3/core/document-service.js";import f from"@typo3/backend/form-engine.js";import{selector as u}from"@typo3/core/literals.js";class g{constructor(){this.initialize=(n,t)=>{const i=document.querySelector(n);i!==null&&(t=t||{},new s("change",l=>{const e=l.target,r=(e.closest("typo3-backend-searchable-select")??e).parentElement.querySelector(".input-group-icon");r!==null&&(r.innerHTML=e.options[e.selectedIndex].dataset.icon);const o=e.closest(".t3js-formengine-field-item").querySelector(".t3js-forms-select-single-icons");if(o!==null){const a=o.querySelector(".form-wizard-icon-list-item button.active, .form-wizard-icon-list-item a.active");a!==null&&a.classList.remove("active");const m=o.querySelector(u`[data-select-index="${e.selectedIndex.toString(10)}"]`);m!==null&&m.closest(".form-wizard-icon-list-item button, .form-wizard-icon-list-item a").classList.add("active")}}).bindTo(i),t.onChange instanceof Array&&new s("change",()=>f.processOnFieldChange(t.onChange)).bindTo(i),new s("click",(l,e)=>{const c=e.closest(".t3js-forms-select-single-icons").querySelector(".form-wizard-icon-list-item button.active, .form-wizard-icon-list-item a.active");c!==null&&c.classList.remove("active"),i.selectedIndex=parseInt(e.dataset.selectIndex,10),i.dispatchEvent(new Event("change")),e.closest(".form-wizard-icon-list-item button, .form-wizard-icon-list-item a").classList.add("active")}).delegateTo(i.closest(".form-control-wrap"),".t3js-forms-select-single-icons .form-wizard-icon-list-item button:not(.active), .t3js-forms-select-single-icons .form-wizard-icon-list-item a:not(.active)"))}}initializeOnReady(n,t){d.ready().then(()=>{this.initialize(n,t)})}}var v=new g;export{v as default};
