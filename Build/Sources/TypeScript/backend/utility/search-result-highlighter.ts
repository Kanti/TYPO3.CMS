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

import TextNormalizer from '@typo3/core/utility/text-normalizer';

export class SearchResultHighlighter {
  private readonly highlights = new Set<HTMLElement>();

  public static normalize(value: string): string {
    return TextNormalizer.foldCaseAndDiacritics(TextNormalizer.normalizeInvisibleCharacters(value));
  }

  public clear(): void {
    const parents = new Set<Node>();
    for (const highlight of this.highlights) {
      const parent = highlight.parentNode;
      if (parent) {
        highlight.replaceWith(...highlight.childNodes);
        parents.add(parent);
      }
    }
    parents.forEach(parent => parent.normalize());
    this.highlights.clear();
  }

  public highlight(entry: HTMLElement, query: string): void {
    const nodes: { node: Text; start: number; end: number }[] = [];
    let text = '';
    const walker = document.createTreeWalker(entry, NodeFilter.SHOW_TEXT, {
      acceptNode: (node): number => node.parentElement.closest('[aria-hidden="true"], .icon, script, style')
        ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
    });
    while (walker.nextNode()) {
      const node = walker.currentNode as Text;
      nodes.push({ node, start: text.length, end: text.length + node.length });
      text += node.data;
    }

    // Map normalized matches back to complete original graphemes, including
    // combining accents and characters taking more than one UTF-16 code unit.
    const positions: { start: number; end: number }[] = [];
    for (const { segment, index } of new Intl.Segmenter().segment(text)) {
      for (let offset = 0; offset < SearchResultHighlighter.normalize(segment).length; offset++) {
        positions.push({ start: index, end: index + segment.length });
      }
    }
    const normalized = SearchResultHighlighter.normalize(text);
    const matches: { start: number; end: number }[] = [];
    let index = normalized.indexOf(query);
    while (index !== -1) {
      const match = { start: positions[index].start, end: positions[index + query.length - 1].end };
      if (matches.at(-1)?.end > match.start) {
        matches.at(-1).end = match.end;
      } else {
        matches.push(match);
      }
      index = normalized.indexOf(query, index + query.length);
    }

    // Work backwards so splitting a text node keeps earlier offsets valid.
    for (const match of matches.reverse()) {
      for (const { node, start, end } of nodes) {
        const from = Math.max(match.start, start) - start;
        const to = Math.min(match.end, end) - start;
        if (from >= to) {
          continue;
        }
        const matchedText = node.splitText(from);
        matchedText.splitText(to - from);
        const highlight = document.createElement('span');
        highlight.className = 'text-highlight';
        matchedText.replaceWith(highlight);
        highlight.append(matchedText);
        this.highlights.add(highlight);
      }
    }
  }

}
