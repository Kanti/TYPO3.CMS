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
import s from"@typo3/core/event/regular-event.js";import m from"@typo3/core/document-service.js";import d from"@typo3/backend/form-engine.js";import{selector as f}from"@typo3/core/literals.js";import{SelectElement as u}from"@typo3/backend/element/select-element.js";class g{constructor(){this.initialize=(c,n)=>{const t=document.querySelector(c);t!==null&&(n=n||{},new s("change",l=>{const e=l.target,i=e.parentElement.querySelector(".input-group-icon");i!==null&&(i.innerHTML=e.options[e.selectedIndex].dataset.icon);const o=e.closest(".t3js-formengine-field-item").querySelector(".t3js-forms-select-single-icons");if(o!==null){const r=o.querySelector(".form-wizard-icon-list-item a.active");r!==null&&r.classList.remove("active");const a=o.querySelector(f`[data-select-index="${e.selectedIndex.toString(10)}"]`);a!==null&&a.closest(".form-wizard-icon-list-item a").classList.add("active")}}).bindTo(t),n.onChange instanceof Array&&new s("change",()=>d.processOnFieldChange(n.onChange)).bindTo(t),new s("click",(l,e)=>{const i=e.closest(".t3js-forms-select-single-icons").querySelector(".form-wizard-icon-list-item a.active");i!==null&&i.classList.remove("active"),t.selectedIndex=parseInt(e.dataset.selectIndex,10),t.dispatchEvent(new Event("change")),e.closest(".form-wizard-icon-list-item a").classList.add("active")}).delegateTo(t.closest(".form-control-wrap"),".t3js-forms-select-single-icons .form-wizard-icon-list-item a:not(.active)"),u.enhance(t))}}initializeOnReady(c,n){m.ready().then(()=>{this.initialize(c,n)})}}var v=new g;export{v as default};
