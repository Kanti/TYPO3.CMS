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
import w from"@typo3/backend/utility.js";import c from"@typo3/backend/preview-controller.js";import{topWindow as l}from"@typo3/core/utility/top-window.js";class d{constructor(){this.windows={},this.localOpen=(n,e,o="newTYPO3frontendWindow",r="")=>this._localOpen(n,e,o,r)}open(...n){return this._localOpen.apply(null,n)}globalOpen(...n){return this._localOpen.apply(null,n)}_localOpen(n,e,o="newTYPO3frontendWindow",r=""){if(!n)return null;if(e===null?e=!window.opener:e===void 0&&(e=!0),new URL(n,window.location.href).searchParams.has("_ps")){const t=l.open("",o,r);return t&&(c.open(t,n),e&&t.focus()),t}const i=this.windows[o]??window.open("",o,r);let s=!1;try{s=i.constructor.name==="Window"}catch{}const p=s&&!i.closed?i.location.href:null;if(w.urlsPointToSameServerSideResource(n,p))return i.location.replace(n),i.location.reload(),i.focus(),i;const a=window.open(n,o,r);return this.windows[o]=a,e&&a.focus(),a}}const f=new d;l.TYPO3.WindowManager||(l.document===window.document?l.TYPO3.WindowManager=f:l.TYPO3.WindowManager=new d);export{f as default};
