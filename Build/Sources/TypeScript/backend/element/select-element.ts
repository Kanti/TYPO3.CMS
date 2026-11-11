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

/**
 * Enhances an existing single select. The native select remains the form control.
 * <typo3-backend-select><select class="form-select">...</select></typo3-backend-select>
 */
export class SelectElement extends HTMLElement {
  private static counter: number = 0;
  public control: HTMLSelectElement = null;
  private readonly highlighter = new SearchResultHighlighter();
  private select: HTMLSelectElement;
  private trigger: HTMLButtonElement;
  private panel: HTMLDivElement;
  private input: HTMLInputElement;
  private list: HTMLDivElement;
  private status: HTMLDivElement;
  private controller: AbortController;
  private observer: MutationObserver;
  private activeIndex: number = -1;
  private filtered: HTMLOptionElement[] = [];
  private open: boolean = false;
  private originalTabindex: string = null;
  private originalAriaHidden: string = null;

  public static enhance(select: HTMLSelectElement): void {
    if (select.multiple || select.size > 1 || select.closest('typo3-backend-select')
      || (select.nextElementSibling instanceof SelectElement && select.nextElementSibling.control === select)) {
      return;
    }
    const element = document.createElement('typo3-backend-select');
    element.control = select;
    select.after(element);
  }

  public connectedCallback(): void {
    // Children may not have been parsed when the custom element connects.
    queueMicrotask(() => {
      if (this.isConnected && !this.controller) {
        this.initialize();
      }
    });
  }

  public disconnectedCallback(): void {
    this.highlighter.clear();
    this.controller?.abort();
    this.observer?.disconnect();
    this.trigger?.remove();
    this.panel?.remove();
    if (this.select) {
      this.select.classList.remove('select-native');
      for (const [name, value] of [['tabindex', this.originalTabindex], ['aria-hidden', this.originalAriaHidden]]) {
        if (value === null) {
          this.select.removeAttribute(name);
        } else {
          this.select.setAttribute(name, value);
        }
      }
    }
    this.controller = null;
  }

  private initialize(): void {
    this.select = this.control ?? this.querySelector('select');
    if (!this.select || this.select.multiple || this.select.size > 1) {
      return;
    }
    this.controller = new AbortController();
    const options = { signal: this.controller.signal };
    const id = `select-popup-${++SelectElement.counter}`;
    this.trigger = document.createElement('button');
    this.trigger.type = 'button';
    this.trigger.className = this.select.className;
    this.trigger.classList.add('select-trigger');
    this.trigger.setAttribute('role', 'combobox');
    this.trigger.setAttribute('aria-haspopup', 'listbox');
    this.trigger.setAttribute('aria-controls', id);
    this.trigger.setAttribute('aria-expanded', 'false');
    this.panel = document.createElement('div');
    this.panel.className = 'select-popup';
    this.panel.hidden = true;
    this.panel.setAttribute('popover', 'manual');
    this.input = document.createElement('input');
    this.input.type = 'search';
    this.input.className = 'form-control select-search';
    this.input.autocomplete = 'off';
    this.input.setAttribute('role', 'combobox');
    this.input.setAttribute('aria-autocomplete', 'list');
    this.input.setAttribute('aria-controls', id);
    this.input.setAttribute('aria-expanded', 'false');
    this.input.setAttribute('aria-label', altDocLabels.get('tcatree.findItem'));
    this.input.placeholder = altDocLabels.get('tcatree.findItem');
    this.list = document.createElement('div');
    this.list.id = id;
    this.list.className = 'select-options';
    this.list.tabIndex = 0;
    this.list.setAttribute('role', 'listbox');
    this.status = document.createElement('div');
    this.status.className = 'select-status';
    this.status.setAttribute('role', 'status');
    this.status.setAttribute('aria-live', 'polite');
    this.panel.append(this.input, this.list, this.status);
    this.append(this.trigger, this.panel);
    this.originalTabindex = this.select.getAttribute('tabindex');
    this.originalAriaHidden = this.select.getAttribute('aria-hidden');
    this.select.classList.add('select-native');
    this.select.setAttribute('aria-hidden', 'true');
    this.select.tabIndex = -1;
    this.trigger.addEventListener('click', () => this.open ? this.close() : this.show(), options);
    this.trigger.addEventListener('keydown', this.handleKeydown, options);
    this.input.addEventListener('keydown', this.handleKeydown, options);
    this.list.addEventListener('keydown', this.handleKeydown, options);
    this.input.addEventListener('input', () => this.filter(), options);
    this.select.addEventListener('change', () => this.sync(), options);
    this.select.addEventListener('focus', () => this.trigger.focus(), options);
    this.select.addEventListener('invalid', () => this.trigger.focus(), options);
    this.select.form?.addEventListener('reset', () => queueMicrotask(() => {
      this.close(false);
      this.sync();
    }), options);
    this.select.labels.forEach(label => label.addEventListener('click', event => {
      event.preventDefault();
      this.trigger.focus();
    }, options));
    document.addEventListener('pointerdown', event => {
      if (!this.contains(event.target as Node)) {
        this.close(false);
      }
    }, options);
    this.addEventListener('focusout', event => {
      if (!this.contains(event.relatedTarget as Node)) {
        this.close(false);
      }
    }, options);
    window.addEventListener('resize', () => this.close(false), options);
    document.addEventListener('scroll', event => {
      if (!this.panel.contains(event.target as Node)) {
        this.close(false);
      }
    }, { ...options, capture: true });
    this.observer = new MutationObserver(() => this.sync());
    this.observer.observe(this.select, { attributes: true, childList: true, subtree: true, characterData: true });
    this.sync();
  }

  private sync(): void {
    this.trigger.textContent = this.select.selectedOptions[0]?.label ?? '';
    this.trigger.disabled = this.select.matches(':disabled');
    const name = this.select.getAttribute('aria-label')
      || Array.from(this.select.labels).map(label => label.textContent.trim()).join(' ');
    this.trigger.setAttribute('aria-label', `${name}: ${this.trigger.textContent}`);
    this.list.setAttribute('aria-label', name);
    for (const attribute of ['aria-labelledby', 'aria-describedby', 'aria-invalid', 'aria-required']) {
      if (this.select.hasAttribute(attribute)) {
        this.trigger.setAttribute(attribute, this.select.getAttribute(attribute));
      } else {
        this.trigger.removeAttribute(attribute);
      }
    }
    if (this.select.required) {
      this.trigger.setAttribute('aria-required', 'true');
    }
    if (this.trigger.disabled) {
      this.close(false);
    } else if (this.open) {
      this.filter();
    }
  }

  private show(initial: string = ''): void {
    if (this.select.matches(':disabled')) {
      return;
    }
    this.open = true;
    this.input.value = initial;
    const count = Array.from(this.select.options).filter(option => !option.hidden && !option.parentElement.hidden).length;
    this.input.hidden = count < 7 && initial === '';
    this.panel.hidden = false;
    this.panel.showPopover();
    const rect = this.trigger.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom - 8;
    const above = rect.top - 8;
    const upwards = below < 240 && above > below;
    this.panel.style.width = `${Math.min(rect.width, window.innerWidth - 16)}px`;
    this.panel.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8))}px`;
    this.panel.style.maxHeight = `${Math.max(0, upwards ? above : below)}px`;
    this.panel.style.top = upwards ? 'auto' : `${rect.bottom + 4}px`;
    this.panel.style.bottom = upwards ? `${window.innerHeight - rect.top + 4}px` : 'auto';
    this.trigger.setAttribute('aria-expanded', 'true');
    this.input.setAttribute('aria-expanded', 'true');
    this.filter();
    const selected = this.filtered.indexOf(this.select.selectedOptions[0]);
    this.highlight(selected >= 0 && !this.isDisabled(this.filtered[selected]) ? selected : this.firstEnabled());
    if (!this.input.hidden) {
      this.input.focus();
    }
  }

  private close(restoreFocus: boolean = true): void {
    if (!this.open) {
      return;
    }
    this.open = false;
    this.highlighter.clear();
    this.panel.hidePopover();
    this.panel.hidden = true;
    this.input.value = '';
    for (const control of [this.trigger, this.input]) {
      control.setAttribute('aria-expanded', 'false');
      control.removeAttribute('aria-activedescendant');
    }
    this.list.removeAttribute('aria-activedescendant');
    if (restoreFocus) {
      this.trigger.focus();
    }
  }

  private isDisabled(option: HTMLOptionElement): boolean {
    return option.disabled || (option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled);
  }

  private firstEnabled(): number {
    return this.filtered.findIndex(option => !this.isDisabled(option));
  }

  private filter(): void {
    this.highlighter.clear();
    const query = SearchResultHighlighter.normalize(this.input.value.trim());
    this.filtered = Array.from(this.select.options).filter(option => !option.hidden
      && !option.parentElement.hidden && SearchResultHighlighter.normalize(option.label).includes(query));
    this.list.replaceChildren();
    let previousGroup: HTMLElement = null;
    let container: HTMLElement = this.list;
    this.filtered.forEach((option, index) => {
      if (option.parentElement !== previousGroup) {
        previousGroup = option.parentElement;
        container = this.list;
        if (previousGroup instanceof HTMLOptGroupElement) {
          container = document.createElement('div');
          container.setAttribute('role', 'group');
          container.setAttribute('aria-label', previousGroup.label);
          const heading = document.createElement('div');
          heading.className = 'select-group-label';
          heading.setAttribute('aria-hidden', 'true');
          heading.textContent = previousGroup.label;
          container.append(heading);
          this.list.append(container);
        }
      }
      const item = document.createElement('div');
      item.id = `${this.list.id}-${index}`;
      item.className = 'select-option';
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', String(option.selected));
      item.setAttribute('aria-disabled', String(this.isDisabled(option)));
      const label = document.createElement('span');
      label.textContent = option.label;
      item.append(label);
      if (query) {
        this.highlighter.highlight(label, query);
      }
      item.addEventListener('pointerdown', event => event.preventDefault());
      item.addEventListener('click', () => this.choose(index));
      container.append(item);
    });
    this.status.textContent = query ? labels.get('results', { count: this.filtered.length }) : '';
    this.status.classList.toggle('visually-hidden', !query || this.filtered.length > 0);
    this.highlight(this.firstEnabled());
  }

  private highlight(index: number): void {
    this.activeIndex = index;
    this.list.querySelectorAll('[role="option"]').forEach((item, position) => {
      item.classList.toggle('active', position === index);
    });
    for (const control of [this.trigger, this.input, this.list]) {
      if (index >= 0) {
        control.setAttribute('aria-activedescendant', `${this.list.id}-${index}`);
      } else {
        control.removeAttribute('aria-activedescendant');
      }
    }
    this.list.querySelector('.active')?.scrollIntoView({ block: 'nearest' });
  }

  private choose(index: number): void {
    const option = this.filtered[index];
    if (!option || this.isDisabled(option) || this.select.matches(':disabled')) {
      return;
    }
    const changed = this.select.selectedIndex !== option.index;
    this.select.selectedIndex = option.index;
    this.close();
    this.sync();
    if (changed) {
      this.select.dispatchEvent(new Event('input', { bubbles: true }));
      this.select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  private readonly handleKeydown = (event: KeyboardEvent): void => {
    if (event.isComposing || this.select.matches(':disabled')) {
      return;
    }
    const searching = event.target === this.input;
    if (event.key === 'Escape' && this.open) {
      event.preventDefault();
      event.stopPropagation();
      this.close();
    } else if (event.key === 'Tab') {
      // Let the browser advance from the trigger, without committing a choice.
      if (event.target !== this.trigger) {
        this.trigger.focus();
      }
      this.close(false);
    } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)
      && (!searching || !['Home', 'End'].includes(event.key))) {
      event.preventDefault();
      if (!this.open) {
        this.show();
        return;
      }
      const step = event.key === 'ArrowUp' || event.key === 'End' ? -1 : 1;
      let index = event.key === 'Home' ? 0 : event.key === 'End' ? this.filtered.length - 1 : this.activeIndex + step;
      while (index >= 0 && index < this.filtered.length && this.isDisabled(this.filtered[index])) {
        index += step;
      }
      if (index >= 0 && index < this.filtered.length) {
        this.highlight(index);
      }
    } else if (event.key === 'Enter' || (!searching && event.key === ' ')) {
      event.preventDefault();
      if (this.open) {
        this.choose(this.activeIndex);
      } else {
        this.show();
      }
    } else if (!searching && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      if (!this.open) {
        this.show(event.key);
      } else {
        this.input.hidden = false;
        this.input.value = event.key;
        this.filter();
        this.input.focus();
      }
      this.input.setSelectionRange(this.input.value.length, this.input.value.length);
    }
  };
}

customElements.define('typo3-backend-select', SelectElement);

declare global {
  interface HTMLElementTagNameMap {
    'typo3-backend-select': SelectElement;
  }
}
