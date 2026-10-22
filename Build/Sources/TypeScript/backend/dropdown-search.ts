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

import altDocLabels from '~labels/backend.alt_doc';
import labels from '~labels/backend.dropdown';
import { SearchResultHighlighter } from '@typo3/backend/utility/search-result-highlighter';

/** Search within a dropdown without replacing its original actions or state. */
export class DropdownSearch {
  private readonly input: HTMLInputElement;
  private readonly searchRow: HTMLLIElement;
  private readonly status: HTMLSpanElement;
  private readonly statusRow: HTMLLIElement;
  private readonly highlighter = new SearchResultHighlighter();
  private readonly originalVisibility = new Map<HTMLElement, HTMLElement['hidden']>();

  constructor(private readonly menu: HTMLElement, private readonly getItems: () => HTMLElement[]) {
    this.searchRow = document.createElement('li');
    this.searchRow.className = 'dropdown-search';
    this.input = document.createElement('input');
    this.input.type = 'search';
    this.input.className = 'form-control';
    this.input.autocomplete = 'off';
    this.input.placeholder = altDocLabels.get('tcatree.findItem');
    this.input.setAttribute('aria-label', altDocLabels.get('tcatree.findItem'));
    this.input.addEventListener('input', () => this.filter());
    this.searchRow.append(this.input);
    this.menu.prepend(this.searchRow);
    this.statusRow = document.createElement('li');
    this.statusRow.className = 'dropdown-search-status visually-hidden';
    this.status = document.createElement('span');
    this.status.setAttribute('role', 'status');
    this.status.setAttribute('aria-live', 'polite');
    this.status.setAttribute('aria-atomic', 'true');
    this.statusRow.append(this.status);
    this.menu.append(this.statusRow);
    this.reset();
  }

  public open(): void {
    this.prepareLabels();
    this.searchRow.hidden = this.input.value === '' && this.optionCount() < 7;
    if (!this.searchRow.hidden) {
      this.input.focus();
    }
  }

  public reset(): void {
    this.highlighter.clear();
    this.input.value = '';
    this.status.textContent = '';
    this.statusRow.classList.add('visually-hidden');
    this.originalVisibility.forEach((hidden, element) => element.hidden = hidden);
    this.originalVisibility.clear();
    this.searchRow.hidden = this.optionCount() < 7;
  }

  public show(value: string): void {
    this.searchRow.hidden = false;
    this.input.value = value;
    this.filter();
    this.input.focus();
    this.input.setSelectionRange(value.length, value.length);
  }

  public handleKeydown(event: KeyboardEvent): boolean {
    if (event.target === this.input) {
      if (event.key === 'Enter' && !event.isComposing) {
        event.preventDefault();
        this.getItems()[0]?.click();
        return true;
      }
      // Preserve native text editing, composition and shortcuts in the input.
      return !['ArrowDown', 'ArrowUp', 'Escape', 'Tab'].includes(event.key) || event.isComposing;
    }
    if (event.key.length === 1 && event.key !== ' ' && !event.isComposing
      && !event.ctrlKey && !event.metaKey && !event.altKey
      && !(event.target instanceof HTMLInputElement) && !(event.target instanceof HTMLTextAreaElement)
      && !(event.target as HTMLElement).isContentEditable) {
      event.preventDefault();
      event.stopPropagation();
      this.show(event.key);
      return true;
    }
    return false;
  }

  private optionCount(): number {
    return Array.from(this.menu.querySelectorAll('.dropdown-item')).filter(item =>
      item.closest('.dropdown-menu') === this.menu && !item.closest('[hidden]')).length;
  }

  private prepareLabels(): void {
    // A highlight must remain inside one flex item; otherwise the item's gap
    // separates the highlighted fragment from the rest of its label.
    this.menu.querySelectorAll('.dropdown-item-spaced').forEach(item => {
      Array.from(item.childNodes).forEach(node => {
        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
          const label = document.createElement('span');
          label.className = 'dropdown-item-label';
          node.replaceWith(label);
          label.append(node);
        }
      });
    });
  }

  private filter(): void {
    this.highlighter.clear();
    this.prepareLabels();
    const query = SearchResultHighlighter.normalize(this.input.value.trim());
    const entries = Array.from(this.menu.children).filter((entry): entry is HTMLElement =>
      entry instanceof HTMLElement && entry !== this.searchRow && entry !== this.statusRow);
    for (const entry of entries) {
      if (!this.originalVisibility.has(entry)) {
        this.originalVisibility.set(entry, entry.hidden);
      }
    }
    const structural = (entry: HTMLElement): boolean => entry.querySelector(':scope > .dropdown-header, :scope > .dropdown-divider') !== null;
    const options = entries.filter(entry => !structural(entry));
    for (const entry of options) {
      // Ignore decorative icons when matching the label.
      const content = entry.cloneNode(true) as HTMLElement;
      content.querySelectorAll('[aria-hidden="true"], .icon').forEach(icon => icon.remove());
      entry.hidden = this.originalVisibility.get(entry) || !SearchResultHighlighter.normalize(content.textContent).includes(query);
      if (query && !entry.hidden) {
        this.highlighter.highlight(entry, query);
      }
    }
    for (const [index, entry] of entries.entries()) {
      if (!structural(entry)) {
        continue;
      }
      if (!query) {
        entry.hidden = this.originalVisibility.get(entry);
        continue;
      }
      const following = entries.slice(index + 1);
      if (entry.querySelector(':scope > .dropdown-header')) {
        const boundary = following.findIndex(structural);
        entry.hidden = this.originalVisibility.get(entry)
          || !following.slice(0, boundary < 0 ? following.length : boundary).some(option => !option.hidden);
      } else {
        const previous = entries.slice(0, index).filter(option => !option.hidden);
        entry.hidden = this.originalVisibility.get(entry)
          || !previous.some(option => !structural(option))
          || !following.some(option => !structural(option) && !option.hidden)
          || previous.at(-1)?.querySelector(':scope > .dropdown-divider') != null;
      }
    }
    const count = options.filter(entry => !entry.hidden).length;
    this.status.textContent = query ? labels.get('results', { count }) : '';
    this.statusRow.classList.toggle('visually-hidden', !query || count > 0);
  }
}
