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
class c{constructor(){this.highlights=new Set}static normalize(n){return n.normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLocaleLowerCase()}clear(){const n=new Set;for(const e of this.highlights){const s=e.parentNode;s&&(e.replaceWith(...e.childNodes),n.add(s))}n.forEach(e=>e.normalize()),this.highlights.clear()}highlight(n,e){const s=[];let a="";const f=document.createTreeWalker(n,NodeFilter.SHOW_TEXT,{acceptNode:t=>t.parentElement.closest('[aria-hidden="true"], .icon, script, style')?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT});for(;f.nextNode();){const t=f.currentNode;s.push({node:t,start:a.length,end:a.length+t.length}),a+=t.data}const d=[];for(const{segment:t,index:l}of new Intl.Segmenter().segment(a))for(let o=0;o<c.normalize(t).length;o++)d.push({start:l,end:l+t.length});const p=c.normalize(a),h=[];let i=p.indexOf(e);for(;i!==-1;){const t={start:d[i].start,end:d[i+e.length-1].end};h.at(-1)?.end>t.start?h.at(-1).end=t.end:h.push(t),i=p.indexOf(e,i+e.length)}for(const t of h.reverse())for(const{node:l,start:o,end:N}of s){const g=Math.max(t.start,o)-o,x=Math.min(t.end,N)-o;if(g>=x)continue;const m=l.splitText(g);m.splitText(x-g);const r=document.createElement("span");r.className="text-highlight",m.replaceWith(r),r.append(m),this.highlights.add(r)}}}export{c as SearchResultHighlighter};
