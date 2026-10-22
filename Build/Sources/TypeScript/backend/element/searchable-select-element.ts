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

import { html, LitElement, nothing, render, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { ifDefined } from 'lit/directives/if-defined.js';
import { unsafeHTML } from 'lit/directives/unsafe-html.js';
import labels from '~labels/backend.messages';
import altDocLabels from '~labels/backend.alt_doc';
import coreLabels from '~labels/core.core';
import { SearchResultHighlighter } from '@typo3/backend/utility/search-result-highlighter';
import '@typo3/backend/element/icon-element';

interface SearchableOption {
  id: string;
  label: string;
  disabled: boolean;
  group: SearchableGroup | null;
  element: HTMLOptionElement;
}

interface SearchableGroup {
  id: string;
  label: string;
  options: SearchableOption[];
}

type Entry = SearchableOption | SearchableGroup;
let idCounter = 0;

/**
 * Shared search for native single selects and existing dropdown menus.
 * The source select owns form values; dropdown actions keep their original DOM.
 */
@customElement('typo3-backend-searchable-select')
export class SearchableSelectElement extends LitElement {
  @property({ type: String }) public label = '';
  @state() private isOpen = false;
  @state() private query = '';
  @state() private searchVisible = false;
  @state() private highlightedId: string | null = null;
  @state() private entries: Entry[] = [];
  @state() private announcement = '';

  private select: HTMLSelectElement | null = null;
  private menu: HTMLElement | null = null;
  private menuTrigger: HTMLElement | null = null;
  private searchRow: HTMLLIElement | null = null;
  private statusRow: HTMLLIElement | null = null;
  private controller: AbortController | null = null;
  private observer: MutationObserver | null = null;
  private readonly internalId = 'searchable-select-' + idCounter++;
  private readonly highlighter = new SearchResultHighlighter();
  private readonly originalVisibility = new Map<HTMLElement, HTMLElement['hidden']>();
  private originalTabindex: string | null = null;
  private originalAriaHidden: string | null = null;
  private announcementTimeout: number | null = null;
  private lastAnnouncedGroup: SearchableGroup | null = null;
  private scrollToHighlight = false;

  public get value(): string {
    return this.select?.value ?? '';
  }

  public set value(value: string) {
    if (this.select) {
      this.select.value = value;
      this.readSelect();
    }
  }

  public override connectedCallback(): void {
    super.connectedCallback();
    queueMicrotask(() => this.initialize());
  }

  public override disconnectedCallback(): void {
    super.disconnectedCallback();
    this.close(false);
    this.controller?.abort();
    this.controller = null;
    this.observer?.disconnect();
    this.highlighter.clear();
    if (this.select) {
      this.select.classList.remove('searchable-select-native');
      for (const [name, value] of [['tabindex', this.originalTabindex], ['aria-hidden', this.originalAriaHidden]]) {
        if (value === null) {
          this.select.removeAttribute(name);
        } else {
          this.select.setAttribute(name, value);
        }
      }
    }
    this.searchRow?.remove();
    this.statusRow?.remove();
    this.searchRow = this.statusRow = null;
  }

  public override focus(options?: FocusOptions): void {
    this.getTrigger()?.focus(options);
  }

  protected override createRenderRoot(): HTMLElement {
    return this;
  }

  protected override willUpdate(): void {
    this.highlighter.clear();
  }

  protected override updated(): void {
    if (this.menu) {
      if (this.isOpen) {
        this.filterMenu(false);
      }
      this.renderMenuSearch();
      return;
    }
    const query = SearchResultHighlighter.normalize(this.query.trim());
    if (query) {
      this.querySelectorAll<HTMLElement>('.searchable-select-option-label').forEach(label => this.highlighter.highlight(label, query));
    }
    if (this.isOpen && this.scrollToHighlight && this.highlightedId) {
      this.scrollToActiveOption();
    }
    this.scrollToHighlight = false;
  }

  protected override render(): TemplateResult | typeof nothing {
    if (!this.select) {
      return nothing;
    }
    const name = this.getAccessibleName();
    const selected = this.select.selectedOptions[0]?.label ?? '';
    return html`
      <button type="button" class="${this.select.className.replace('searchable-select-native', '')} searchable-select-trigger"
        role="combobox" aria-haspopup="listbox" aria-expanded="${this.isOpen}" aria-controls="${this.internalId}-listbox"
        aria-activedescendant="${ifDefined(this.isOpen ? this.highlightedId ?? undefined : undefined)}"
        aria-label="${name}: ${selected}" aria-describedby="${ifDefined(this.select.getAttribute('aria-describedby') ?? undefined)}"
        aria-invalid="${ifDefined(this.select.getAttribute('aria-invalid') ?? undefined)}"
        aria-required="${this.select.required}" ?disabled="${this.select.matches(':disabled')}"
        @click="${() => this.isOpen ? this.close() : this.open()}">${selected}</button>
      <div class="searchable-select-popup" popover="manual">
        ${this.renderSearch()}
        <div class="searchable-select-options" id="${this.internalId}-listbox" role="listbox" tabindex="0" aria-label="${name}">
          ${this.entries.map(entry => 'options' in entry ? this.renderGroup(entry) : this.renderOption(entry))}
        </div>
        <div class="searchable-select-status ${this.getVisibleOptions().length ? 'visually-hidden' : ''}" role="status" aria-live="polite" aria-atomic="true">${this.announcement}</div>
      </div>
    `;
  }

  private initialize(): void {
    if (!this.isConnected || this.controller) {
      return;
    }
    this.select = this.querySelector(':scope > select');
    const likelyOverlay = window.matchMedia(
      '(pointer: coarse) and (hover: none)'
    ).matches;
    if (this.select && (this.select.multiple || this.select.size > 1 || likelyOverlay)) {
      this.select = null;
      return;
    }
    this.controller = new AbortController();
    const options = { signal: this.controller.signal };
    this.addEventListener('keydown', this.handleKeydown, { ...options, capture: true });
    window.addEventListener('resize', this.positionListbox, options);
    window.addEventListener('scroll', this.positionListbox, { ...options, capture: true });
    window.visualViewport?.addEventListener('resize', this.positionListbox, options);
    window.visualViewport?.addEventListener('scroll', this.positionListbox, options);
    window.addEventListener('blur', () => this.close(false), options);
    if (!this.select) {
      this.initializeMenu(options);
      return;
    }
    this.originalTabindex = this.select.getAttribute('tabindex');
    this.originalAriaHidden = this.select.getAttribute('aria-hidden');
    this.select.classList.add('searchable-select-native');
    this.select.tabIndex = -1;
    this.select.setAttribute('aria-hidden', 'true');
    this.select.addEventListener('change', () => this.readSelect(), options);
    this.select.addEventListener('focus', () => this.focus(), options);
    this.select.addEventListener('invalid', () => this.focus(), options);
    this.select.form?.addEventListener('reset', () => queueMicrotask(() => {
      this.close(false);
      this.readSelect();
    }), options);
    document.addEventListener('pointerdown', event => {
      if (!this.contains(event.target as Node)) {
        this.close(false);
      }
    }, options);
    this.addEventListener('focusout', () => queueMicrotask(() => {
      if (!this.contains(document.activeElement)) {
        this.close(false);
      }
    }), options);
    this.observer = new MutationObserver(() => this.readSelect());
    this.observer.observe(this.select, { subtree: true, childList: true, characterData: true, attributes: true });
    this.readSelect();
  }

  private renderSearch(): TemplateResult {
    const listbox = this.select ? this.internalId + '-listbox' : this.menu.id;
    const clearLabel = coreLabels.get('labels.inputfield.clearButton.title');
    return html`<div class="form-control-clearable-wrapper searchable-select-search-field" ?hidden="${!this.searchVisible}">
      <input type="search" class="form-control searchable-select-search" autocomplete="off" autocapitalize="off" spellcheck="false"
        role="${ifDefined(this.select ? 'combobox' : undefined)}" aria-autocomplete="${ifDefined(this.select ? 'list' : undefined)}"
        aria-expanded="${ifDefined(this.select ? String(this.isOpen) : undefined)}" aria-controls="${listbox}"
        aria-activedescendant="${ifDefined(this.select && this.isOpen ? this.highlightedId ?? undefined : undefined)}"
        aria-label="${altDocLabels.get('tcatree.findItem')}" placeholder="${altDocLabels.get('tcatree.findItem')}"
        ?hidden="${!this.searchVisible}" .value="${this.query}" @input="${this.handleInput}">
      <button type="button" class="close searchable-select-clear" tabindex="-1" title="${clearLabel}" aria-label="${clearLabel}"
        ?hidden="${!this.query}" @mousedown="${(event: MouseEvent) => event.preventDefault()}" @click="${this.clearSearch}"></button>
    </div>`;
  }

  private renderGroup(group: SearchableGroup): TemplateResult {
    return html`<div class="searchable-select-group" role="group" aria-labelledby="${group.id}" ?hidden="${!group.options.some(option => this.isVisible(option))}">
      <div class="searchable-select-group-label" id="${group.id}">${group.label}</div>
      ${group.options.map(option => this.renderOption(option))}
    </div>`;
  }

  private renderOption(option: SearchableOption): TemplateResult | typeof nothing {
    if (!this.isVisible(option)) {
      return nothing;
    }
    return html`<div class="searchable-select-option" id="${option.id}" role="option"
      aria-selected="${option.element.selected}" aria-disabled="${ifDefined(option.disabled ? 'true' : undefined)}"
      ?data-active="${option.id === this.highlightedId}" @mouseenter="${() => !option.disabled && this.highlight(option, false)}"
      @mousedown="${(event: MouseEvent) => event.preventDefault()}"
      @click="${() => this.commit(option)}">
      ${option.element.dataset.icon ? html`<span class="searchable-select-option-icon" aria-hidden="true">${unsafeHTML(option.element.dataset.icon)}</span>` : nothing}
      <span class="searchable-select-option-label">${option.label}</span>
      ${option.element.selected ? html`<span class="searchable-select-option-indicator" aria-hidden="true"><typo3-backend-icon identifier="actions-check" size="small"></typo3-backend-icon></span>` : nothing}
    </div>`;
  }

  private getAccessibleName(): string {
    return this.select.getAttribute('aria-label')
      || this.select.getAttribute('aria-labelledby')?.split(/\s+/).map(id => document.getElementById(id)?.textContent ?? '').join(' ')
      || Array.from(this.select.labels ?? []).map(label => label.textContent.trim()).join(' ')
      || this.label || this.select.name;
  }

  private readSelect(): void {
    this.highlighter.clear();
    const entries: Entry[] = [];
    let count = 0;
    const createOption = (element: HTMLOptionElement, group: SearchableGroup | null): SearchableOption => ({
      id: `${this.internalId}-option-${count++}`, label: element.label, group, element,
      disabled: element.disabled || (element.parentElement instanceof HTMLOptGroupElement && element.parentElement.disabled),
    });
    for (const child of Array.from(this.select.children)) {
      if (child instanceof HTMLOptionElement && !child.hidden) {
        entries.push(createOption(child, null));
      } else if (child instanceof HTMLOptGroupElement && !child.hidden) {
        const group: SearchableGroup = { id: `${this.internalId}-group-${entries.length}`, label: child.label, options: [] };
        group.options = Array.from(child.children).filter((option): option is HTMLOptionElement => option instanceof HTMLOptionElement && !option.hidden)
          .map(option => createOption(option, group));
        entries.push(group);
      }
    }
    this.entries = entries;
    if (this.select.matches(':disabled')) {
      this.close();
    }
    this.requestUpdate();
  }

  private getOptions(): SearchableOption[] {
    return this.entries.flatMap(entry => 'options' in entry ? entry.options : [entry]);
  }

  private isVisible(option: SearchableOption): boolean {
    return SearchResultHighlighter.normalize(option.label).includes(SearchResultHighlighter.normalize(this.query.trim()));
  }

  private getVisibleOptions(): SearchableOption[] {
    return this.getOptions().filter(option => this.isVisible(option));
  }

  private getTrigger(): HTMLElement | null {
    return this.menuTrigger ?? this.querySelector('.searchable-select-trigger');
  }

  private getInput(): HTMLInputElement | null {
    return this.searchRow?.querySelector('input') ?? this.querySelector('.searchable-select-search');
  }

  private async open(initial = ''): Promise<void> {
    if (this.select?.matches(':disabled') || this.menuTrigger?.matches(':disabled')) {
      return;
    }
    this.query = initial;
    this.lastAnnouncedGroup = null;
    if (this.menu) {
      this.searchVisible = initial !== '' || this.menuOptionCount() >= 7;
      this.menu.showPopover();
      this.isOpen = true;
      this.filterMenu();
      this.renderMenuSearch();
    } else {
      this.readSelect();
      this.searchVisible = initial !== '' || this.getOptions().length >= 7;
      this.isOpen = true;
      this.highlight(this.getVisibleOptions().find(option => option.element.selected && !option.disabled)
        ?? this.getVisibleOptions().find(option => !option.disabled) ?? null, false);
      await this.updateComplete;
      if (!this.isOpen || !this.isConnected) {
        return;
      }
      this.querySelector<HTMLElement>('.searchable-select-popup').showPopover();
      this.positionListbox();
      this.announceResults();
    }
    if (this.searchVisible) {
      const input = this.getInput();
      input?.focus();
      input?.setSelectionRange(this.query.length, this.query.length);
    } else {
      this.getTrigger()?.focus();
    }
  }

  private close(restoreFocus = true, hidePopover = true): void {
    this.highlighter.clear();
    if (this.announcementTimeout !== null) {
      clearTimeout(this.announcementTimeout);
      this.announcementTimeout = null;
    }
    const wasOpen = this.isOpen;
    this.isOpen = false;
    this.query = '';
    this.highlightedId = null;
    this.announcement = '';
    this.originalVisibility.forEach((hidden, element) => element.hidden = hidden);
    this.originalVisibility.clear();
    if (this.menu) {
      this.menuTrigger?.setAttribute('aria-expanded', 'false');
      this.searchVisible = this.menuOptionCount() >= 7;
      this.renderMenuSearch();
    }
    const popup = this.menu ?? this.querySelector<HTMLElement>('.searchable-select-popup');
    if (hidePopover && popup?.matches(':popover-open')) {
      popup.hidePopover();
    }
    if (restoreFocus && wasOpen) {
      this.focus();
    }
  }

  private commit(option: SearchableOption): void {
    if (option.disabled) {
      return;
    }
    const hasChanged = !option.element.selected;
    option.element.selected = true;
    this.close();
    this.requestUpdate();
    if (hasChanged) {
      this.select.dispatchEvent(new Event('input', { bubbles: true }));
      this.select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  private highlight(option: SearchableOption | null, announceGroup: boolean): void {
    this.highlightedId = option?.id ?? null;
    this.scrollToHighlight = true;
    this.requestUpdate();
    if (announceGroup && option?.group && option.group !== this.lastAnnouncedGroup) {
      this.announce(labels.get('searchable_select.group', { group: option.group.label, count: option.group.options.filter(item => this.isVisible(item)).length }));
    }
    this.lastAnnouncedGroup = option?.group ?? null;
  }

  private scrollToActiveOption(): void {
    const list = this.menu ?? this.querySelector<HTMLElement>('.searchable-select-options');
    const active = this.menu ? document.activeElement
      : this.highlightedId ? this.querySelector<HTMLElement>('#' + CSS.escape(this.highlightedId)) : null;
    if (!list || !(active instanceof HTMLElement) || !list.contains(active)
      || this.menu && active.closest('.dropdown-menu') !== this.menu) {
      return;
    }
    const first = this.menu
      ? this.menuItems().find(item => !item.matches(':disabled, .disabled, [aria-disabled="true"]'))
      : list.querySelector('.searchable-select-option:not([aria-disabled="true"])');
    if (active === first || this.searchRow?.contains(active)) {
      list.scrollTop = 0;
      return;
    }
    if (active.getClientRects().length === 0) {
      return;
    }
    const bounds = list.getBoundingClientRect();
    const rect = active.getBoundingClientRect();
    const style = getComputedStyle(active);
    const outline = Math.max(0, parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset));
    const top = Math.max(bounds.top + list.clientTop, this.menu && this.searchVisible
      ? this.searchRow.getBoundingClientRect().bottom : bounds.top);
    const bottom = bounds.top + list.clientTop + list.clientHeight;
    if (rect.top - outline < top) {
      list.scrollTop += Math.floor(rect.top - outline - top);
    } else if (rect.bottom + outline > bottom) {
      list.scrollTop += Math.ceil(rect.bottom + outline - bottom);
    }
  }

  private announceResults(): void {
    const count = this.menu ? this.menuItems().length : this.getVisibleOptions().length;
    this.announce(this.query ? labels.get('searchable_select.results', { count }) : '', count === 0 ? 0 : 500);
  }

  private announce(message: string, delay = 0): void {
    if (this.announcementTimeout !== null) {
      clearTimeout(this.announcementTimeout);
    }
    if (delay === 0) {
      this.announcement = message;
    } else {
      this.announcementTimeout = window.setTimeout(() => this.announcement = message, delay);
    }
  }

  private readonly positionListbox = (event?: Event): void => {
    if (!this.isOpen || event?.type === 'scroll' && event.target instanceof Node && this.contains(event.target)) {
      return;
    }
    if (this.menu) {
      this.scrollToActiveOption();
      return;
    }
    if (!this.select) {
      return;
    }
    const popup = this.querySelector<HTMLElement>('.searchable-select-popup');
    const rect = this.getTrigger().getBoundingClientRect();
    const viewport = window.visualViewport;
    const top = viewport?.offsetTop ?? 0;
    const left = viewport?.offsetLeft ?? 0;
    const width = viewport?.width ?? window.innerWidth;
    const below = top + (viewport?.height ?? window.innerHeight) - rect.bottom - 8;
    const above = rect.top - top - 8;
    const upwards = below < 240 && above > below;
    popup.style.width = `${Math.min(rect.width, width - 16)}px`;
    popup.style.left = `${Math.max(left + 8, Math.min(rect.left, left + width - rect.width - 8))}px`;
    popup.style.maxHeight = `${Math.max(0, upwards ? above : below)}px`;
    popup.style.top = upwards ? 'auto' : `${rect.bottom + 4}px`;
    popup.style.bottom = upwards ? `${window.innerHeight - rect.top + 4}px` : 'auto';
    this.scrollToActiveOption();
  };

  private readonly clearSearch = (): void => {
    const input = this.getInput();
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
  };

  private readonly handleInput = (event: InputEvent): void => {
    event.stopPropagation();
    this.highlighter.clear();
    this.query = (event.target as HTMLInputElement).value;
    if (this.menu) {
      this.filterMenu();
    } else {
      this.highlight(this.getVisibleOptions().find(option => !option.disabled) ?? null, false);
    }
    this.announceResults();
    if (this.menu) {
      this.renderMenuSearch();
    }
  };

  private readonly handleKeydown = (event: KeyboardEvent): void => {
    const target = event.target as HTMLElement;
    const input = target === this.getInput();
    if (target instanceof HTMLInputElement && !input || target instanceof HTMLTextAreaElement || target.isContentEditable) {
      return;
    }
    if (this.menu && target.closest('.dropdown-menu') && target.closest('.dropdown-menu') !== this.menu) {
      return;
    }
    if (event.isComposing) {
      if (input) {
        event.stopPropagation();
      }
      return;
    }
    if (event.key === 'Tab') {
      this.close();
      return;
    }
    if (event.key === 'Escape' && this.isOpen) {
      event.preventDefault();
      event.stopPropagation();
      this.close();
      return;
    }
    if (['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Home', 'End'].includes(event.key) && (!input || !['Home', 'End'].includes(event.key))) {
      event.preventDefault();
      event.stopPropagation();
      if (!this.isOpen) {
        void this.open();
      } else if (this.menu) {
        const items = this.menuItems().filter(item => !item.matches(':disabled, .disabled, [aria-disabled="true"]'));
        const index = items.indexOf(document.activeElement as HTMLElement);
        if (event.key === 'ArrowUp' && (input || index === 0) && this.searchVisible) {
          this.getInput()?.focus({ preventScroll: true });
        } else {
          items[this.nextIndex(event.key, index, items.length)]?.focus({ preventScroll: true });
        }
        this.scrollToActiveOption();
      } else {
        const options = this.getVisibleOptions().filter(option => !option.disabled);
        const index = options.findIndex(option => option.id === this.highlightedId);
        this.highlight(options[this.nextIndex(event.key, index, options.length)] ?? null, true);
      }
      return;
    }
    if (event.key === 'Enter' && !target.closest('.searchable-select-clear') && (input || !this.menu)) {
      event.preventDefault();
      event.stopPropagation();
      if (!this.isOpen) {
        void this.open();
      } else if (this.menu) {
        this.menuItems().find(item => !item.matches(':disabled, .disabled, [aria-disabled="true"]'))?.click();
      } else {
        const option = this.getVisibleOptions().find(option => option.id === this.highlightedId);
        if (option) {
          this.commit(option);
        }
      }
      return;
    }
    const backspace = event.key === 'Backspace' && this.isOpen;
    if (!input && ((event.key.length === 1 && event.key !== ' ') || backspace) && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      event.stopPropagation();
      if (!this.isOpen) {
        void this.open(event.key);
      } else {
        this.searchVisible = true;
        this.query = backspace
          ? this.query.slice(0, Array.from(new Intl.Segmenter().segment(this.query)).at(-1)?.index ?? 0)
          : this.query + event.key;
        if (this.menu) {
          this.filterMenu();
          this.renderMenuSearch();
          this.getInput().focus();
        } else {
          this.highlight(this.getVisibleOptions().find(option => !option.disabled) ?? null, false);
          void this.updateComplete.then(() => this.getInput()?.focus());
        }
      }
    }
    if (input) {
      event.stopPropagation();
    }
  };

  private nextIndex(key: string, index: number, length: number): number {
    if (key === 'Home') {
      return 0;
    }
    if (key === 'End') {
      return length - 1;
    }
    const step = key.endsWith('Down') ? (key === 'PageDown' ? 10 : 1) : (key === 'PageUp' ? -10 : -1);
    return index < 0 ? (step > 0 ? 0 : length - 1) : Math.max(0, Math.min(length - 1, index + step));
  }

  private initializeMenu(options: AddEventListenerOptions): void {
    this.menu = this.querySelector('.dropdown-menu');
    this.menuTrigger = this.querySelector('.dropdown-toggle');
    if (!this.menu || !this.menuTrigger) {
      return;
    }
    this.menu.id ||= this.internalId + '-menu';
    this.menu.setAttribute('popover', '');
    this.menuTrigger.setAttribute('popovertarget', this.menu.id);
    this.menuTrigger.removeAttribute('data-bs-toggle');
    if (!this.menuTrigger.closest('.dropdown')) {
      this.menuTrigger.parentElement?.classList.add('dropdown');
    }
    this.searchRow = document.createElement('li');
    this.searchRow.className = 'searchable-select-search-row';
    this.statusRow = document.createElement('li');
    this.statusRow.className = 'searchable-select-status-row visually-hidden';
    this.menu.prepend(this.searchRow);
    this.menu.append(this.statusRow);
    this.menu.addEventListener('focusin', () => this.scrollToActiveOption(), options);
    this.menu.addEventListener('beforetoggle', (event: ToggleEvent) => {
      if (event.newState === 'closed') {
        this.close(false, false);
      }
    }, options);
    this.menu.addEventListener('toggle', (event: ToggleEvent) => {
      if (event.newState === 'open') {
        this.isOpen = true;
        this.menuTrigger?.setAttribute('aria-expanded', 'true');
        this.prepareMenuLabels();
        this.searchVisible = this.query !== '' || this.menuOptionCount() >= 7;
        this.renderMenuSearch();
        (this.searchVisible ? this.getInput() : this.menuTrigger)?.focus();
      }
    }, options);
    this.renderMenuSearch();
  }

  private renderMenuSearch(): void {
    if (!this.searchRow) {
      return;
    }
    this.searchRow.hidden = !this.searchVisible;
    render(this.renderSearch(), this.searchRow);
    render(html`<span role="status" aria-live="polite" aria-atomic="true">${this.announcement}</span>`, this.statusRow);
    this.statusRow.classList.toggle('visually-hidden', !this.query || this.menuItems().length > 0);
  }

  private menuItems(): HTMLElement[] {
    return Array.from(this.menu.querySelectorAll<HTMLElement>('.dropdown-item')).filter(item => item.closest('.dropdown-menu') === this.menu && !item.closest('[hidden]'));
  }

  private menuOptionCount(): number {
    return this.menuItems().length;
  }

  private prepareMenuLabels(): void {
    this.menu.querySelectorAll('.dropdown-item-spaced').forEach(item => {
      Array.from(item.childNodes).forEach(node => {
        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
          const label = document.createElement('span');
          label.className = 'searchable-select-option-label';
          node.replaceWith(label);
          label.append(node);
        }
      });
    });
  }

  private filterMenu(announce = true): void {
    this.highlighter.clear();
    this.prepareMenuLabels();
    const query = SearchResultHighlighter.normalize(this.query.trim());
    const entries = Array.from(this.menu.children).filter((entry): entry is HTMLElement =>
      entry instanceof HTMLElement && entry !== this.searchRow && entry !== this.statusRow);
    for (const entry of entries) {
      if (!this.originalVisibility.has(entry)) {
        this.originalVisibility.set(entry, entry.hidden);
      }
    }
    const structural = (entry: HTMLElement): boolean => entry.querySelector(':scope > .dropdown-header, :scope > .dropdown-divider') !== null;
    for (const entry of entries.filter(entry => !structural(entry))) {
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
    if (announce) {
      this.announceResults();
    }
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'typo3-backend-searchable-select': SearchableSelectElement;
  }
}
