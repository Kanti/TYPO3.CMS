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
import T from"@typo3/core/utility/text-normalizer.js";class c{constructor(){this.highlights=new Set}static normalize(n){return T.foldCaseAndDiacritics(T.normalizeInvisibleCharacters(n))}clear(){const n=new Set;for(const e of this.highlights){const o=e.parentNode;o&&(e.replaceWith(...e.childNodes),n.add(o))}n.forEach(e=>e.normalize()),this.highlights.clear()}highlight(n,e){const o=[];let i="";const f=document.createTreeWalker(n,NodeFilter.SHOW_TEXT,{acceptNode:t=>t.parentElement.closest('[aria-hidden="true"], .icon, script, style')?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT});for(;f.nextNode();){const t=f.currentNode;o.push({node:t,start:i.length,end:i.length+t.length}),i+=t.data}const d=[];for(const{segment:t,index:h}of new Intl.Segmenter().segment(i))for(let s=0;s<c.normalize(t).length;s++)d.push({start:h,end:h+t.length});const p=c.normalize(i),r=[];let a=p.indexOf(e);for(;a!==-1;){const t={start:d[a].start,end:d[a+e.length-1].end};r.at(-1)?.end>t.start?r.at(-1).end=t.end:r.push(t),a=p.indexOf(e,a+e.length)}for(const t of r.reverse())for(const{node:h,start:s,end:N}of o){const g=Math.max(t.start,s)-s,x=Math.min(t.end,N)-s;if(g>=x)continue;const m=h.splitText(g);m.splitText(x-g);const l=document.createElement("span");l.className="text-highlight",m.replaceWith(l),l.append(m),this.highlights.add(l)}}}export{c as SearchResultHighlighter};
