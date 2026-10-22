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
import a from"@typo3/backend/notification.js";import l from"@typo3/core/event/regular-event.js";import t from"~labels/styleguide.messages";class n{constructor(){new l("change",(o,e)=>{const c=e.selectedOptions[0];a.info(t.get("component.select.searchable.notification.title"),t.get("component.select.searchable.notification.message",{label:c?.label??"",value:e.value}))}).delegateTo(document,"typo3-backend-searchable-select > select"),new l("click",(o,e)=>{o.preventDefault(),e.closest(".dropdown-menu").querySelector('[aria-selected="true"]')?.removeAttribute("aria-selected"),e.setAttribute("aria-selected","true"),a.info(t.get("component.select.searchable.notification.title"),t.get("component.select.searchable.notification.message",{label:e.title,value:e.getAttribute("href")}))}).delegateTo(document,"typo3-backend-searchable-select .dropdown-item")}}var r=new n;export{r as default};
