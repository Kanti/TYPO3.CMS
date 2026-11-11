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

import '@typo3/backend/dropdown.js';
import '@typo3/backend/element/searchable-select-element.js';
import type { SearchableSelectElement } from '@typo3/backend/element/searchable-select-element.js';
import { expect, fixtureSync, html, oneEvent } from '@open-wc/testing';
import { stub, type SinonStub } from 'sinon';
import type { } from 'mocha';

describe('@typo3/backend/dropdown search', () => {
  let root: HTMLElement;
  let trigger: HTMLButtonElement;
  let menu: HTMLElement;
  let input: HTMLInputElement;

  const key = (target: HTMLElement, value: string, options: KeyboardEventInit = {}): KeyboardEvent => {
    const event = new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true, ...options });
    target.dispatchEvent(event);
    return event;
  };
  const items = (): HTMLButtonElement[] => Array.from(menu.querySelectorAll<HTMLButtonElement>(':scope > li:not([hidden]) > .dropdown-item'));
  const toggle = async (action: () => void): Promise<void> => {
    const toggled = oneEvent(menu, 'toggle', false);
    action();
    await toggled;
  };
  const open = async (count = 5): Promise<void> => {
    menu.querySelectorAll('[data-test-extra]').forEach(item => item.remove());
    const existing = menu.querySelectorAll(':scope > li:not([hidden]) > .dropdown-item').length;
    for (let index = existing; index < count; index++) {
      const row = document.createElement('li');
      row.dataset.testExtra = '';
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'dropdown-item';
      item.textContent = `Zulu ${index + 1}`;
      row.append(item);
      menu.insertBefore(row, menu.querySelector('.searchable-select-status-row'));
    }
    trigger.focus();
    await toggle(() => menu.showPopover());
    input = menu.querySelector('input');
  };
  const filter = (value: string): void => {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };

  beforeEach(async () => {
    root = fixtureSync(html`
      <div>
        <typo3-backend-searchable-select><div class="dropdown">
          <button type="button" class="dropdown-toggle" popovertarget="search-menu">Languages</button>
          <ul id="search-menu" class="dropdown-menu" popover >
            <li><button type="button" class="dropdown-item">English</button></li>
            <li><button type="button" class="dropdown-item">Deutsch</button></li>
            <li><button type="button" class="dropdown-item" disabled>Español</button></li>
            <li><button type="button" class="dropdown-item">Français</button></li>
            <li><button type="button" class="dropdown-item">Português</button></li>
            <li hidden><button type="button" class="dropdown-item">Hidden</button></li>
          </ul>
        </div></typo3-backend-searchable-select>
        <button type="button" id="next">Next</button>
      </div>
    `);
    const component = root.querySelector<SearchableSelectElement>('typo3-backend-searchable-select');
    await component.updateComplete;
    await component.updateComplete;
    trigger = root.querySelector('.dropdown-toggle');
    menu = root.querySelector('.dropdown-menu');
  });

  describe('with primary touch input', () => {
    let matchMediaStub: SinonStub;

    before(() => {
      const matchMedia = window.matchMedia.bind(window);
      matchMediaStub = stub(window, 'matchMedia').callsFake((query: string): MediaQueryList => {
        const result = matchMedia(query);
        if (query === '(pointer: coarse) and (hover: none)') {
          Object.defineProperty(result, 'matches', { value: true });
        }
        return result;
      });
    });

    after(() => matchMediaStub.restore());

    it('still initializes and filters the dropdown search', async () => {
      await open(7);
      expect(input.hidden).to.be.false;
      expect(document.activeElement === input).to.be.true;
      filter('fr');
      expect(items().map(item => item.textContent)).to.eql(['Français']);
      expect(trigger.getAttribute('aria-expanded')).to.equal('true');
    });
  });

  it('focuses search immediately for seven options', async () => {
    await open(7);
    expect(input.closest('li').hidden).to.be.false;
    expect(document.activeElement === input).to.be.true;
    expect(input.type).to.equal('search');
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
    expect(input.getAttribute('aria-label')).not.to.equal('');
    expect(key(input, 'ArrowUp').defaultPrevented).to.be.true;
    expect(document.activeElement === input).to.be.true;
    expect(input.value).to.equal('');
  });

  it('shows search starting at seven options and restores it after filtering', async () => {
    await open(6);
    expect(input.closest('li').hidden).to.be.true;
    await toggle(() => menu.hidePopover());
    await open(7);
    expect(input.closest('li').hidden).to.be.false;
    filter('Deutsch');
    await toggle(() => menu.hidePopover());
    await open(7);
    expect(input.closest('li').hidden).to.be.false;
    expect(items()).to.have.length(7);
  });

  it('reveals search on typing and preserves the first character', async () => {
    await open();
    expect(input.closest('li').hidden).to.be.true;
    key(trigger, 'f');
    expect(input.value).to.equal('f');
    expect(document.activeElement === input).to.be.true;
    expect(items().map(item => item.textContent)).to.eql(['Français']);
  });

  it('focuses the trigger after clicking from outside and reveals search while typing', async () => {
    root.querySelector<HTMLButtonElement>('#next').focus();
    await toggle(() => trigger.click());
    input = menu.querySelector('input');
    expect(menu.matches(':popover-open')).to.be.true;
    expect(input.closest('li').hidden).to.be.true;
    expect(document.activeElement === trigger).to.be.true;

    for (const character of 'fr') {
      const target = document.activeElement as HTMLElement;
      if (!key(target, character).defaultPrevented && target === input) {
        filter(input.value + character);
      }
    }
    expect(input.closest('li').hidden).to.be.false;
    expect(document.activeElement === input).to.be.true;
    expect(input.value).to.equal('fr');
    expect(items().map(item => item.textContent)).to.eql(['Français']);
    expect(menu.matches(':popover-open')).to.be.true;
  });

  it('opens and searches on a closed trigger', async () => {
    trigger.focus();
    await toggle(() => key(trigger, 'p'));
    input = menu.querySelector('input');
    expect(menu.matches(':popover-open')).to.be.true;
    expect(input.value).to.equal('p');
    expect(document.activeElement === input).to.be.true;
  });

  it('filters case and accents without revealing originally hidden entries', async () => {
    await open(7);
    filter('FRANCAIS');
    expect(items().map(item => item.textContent)).to.eql(['Français']);
    filter('');
    expect(items()).to.have.length(7);
    expect(menu.querySelector('[role="status"]').textContent).to.equal('');
  });

  it('clears only the search, restores the menu and keeps focus without activating an item', async () => {
    await open();
    let clicks = 0;
    menu.querySelectorAll('.dropdown-item').forEach(item => item.addEventListener('click', () => clicks++));
    key(trigger, 'f');
    const clear = menu.querySelector<HTMLButtonElement>('.searchable-select-clear');
    expect(clear.hidden).to.be.false;
    expect(clear.getAttribute('aria-label')).not.to.equal('');
    clear.click();

    expect(input.value).to.equal('');
    expect(document.activeElement === input).to.be.true;
    expect(clear.hidden).to.be.true;
    expect(input.closest('li').hidden).to.be.false;
    expect(menu.matches(':popover-open')).to.be.true;
    expect(items()).to.have.length(5);
    expect(menu.querySelector('.text-highlight') === null).to.be.true;
    expect(clicks).to.equal(0);
    await expect(root).to.be.accessible();
  });

  it('highlights every matching occurrence with the original spelling and accents', async () => {
    await open(7);
    filter('FRANCAIS');
    expect(items()[0].querySelector('.text-highlight').textContent).to.equal('Français');
    filter('AN');
    const item = menu.querySelectorAll<HTMLButtonElement>('.dropdown-item')[0];
    item.textContent = 'Ananas';
    filter('AN');
    expect(Array.from(item.querySelectorAll('.text-highlight')).map(mark => mark.textContent)).to.eql(['An', 'an']);
  });

  it('highlights conventional spellings and text containing invisible characters', async () => {
    await open(7);
    const item = menu.querySelector<HTMLButtonElement>('.dropdown-item');
    for (const [label, query] of [['Straße', 'STRASSE'], ['Łódź', 'lodz'], ['Don\u00adau', 'donau']]) {
      filter('');
      item.textContent = label;
      filter(query);
      expect(items().includes(item)).to.be.true;
      expect(item.querySelector('.text-highlight').textContent).to.equal(label);
    }
  });

  it('maps accent-insensitive matches to the original Unicode text', async () => {
    await open(7);
    const item = menu.querySelector<HTMLButtonElement>('.dropdown-item');
    item.textContent = '🍦 Cre\u0300me Crème';
    filter('CREME');
    expect(Array.from(item.querySelectorAll('.text-highlight')).map(mark => mark.textContent)).to.eql(['Cre\u0300me', 'Crème']);
    expect(item.textContent).to.equal('🍦 Cre\u0300me Crème');
  });

  it('preserves icons, nested formatting, literal text and original action listeners', async () => {
    await open(7);
    const item = menu.querySelector<HTMLButtonElement>('.dropdown-item');
    item.innerHTML = '<span class="icon" aria-hidden="true">Français</span>Fr<strong>ançais</strong> &lt;img src=x&gt;';
    const icon = item.querySelector('.icon');
    const strong = item.querySelector('strong');
    let clicks = 0;
    item.addEventListener('click', () => clicks++);
    filter('francais');
    expect(Array.from(item.querySelectorAll('.text-highlight')).map(mark => mark.textContent)).to.eql(['Fr', 'ançais']);
    expect(icon.querySelector('.text-highlight') === null).to.be.true;
    expect(item.querySelector('strong') === strong).to.be.true;
    filter('<img');
    expect(item.querySelector('.text-highlight').textContent).to.equal('<img');
    expect(item.querySelector('img') === null).to.be.true;
    key(input, 'Enter');
    expect(clicks).to.equal(1);
    expect(item.querySelector('strong') === strong).to.be.true;
    expect(item.querySelector('.icon') === icon).to.be.true;
    expect(item.querySelector('.text-highlight') === null).to.be.true;
  });

  it('updates highlights and removes them on clearing or closing', async () => {
    await open(7);
    filter('fr');
    expect(menu.querySelector('.text-highlight').textContent).to.equal('Fr');
    filter('de');
    expect(Array.from(menu.querySelectorAll('.text-highlight')).map(mark => mark.textContent)).to.eql(['De']);
    filter('');
    expect(menu.querySelector('.text-highlight') === null).to.be.true;
    filter('fr');
    menu.hidePopover();
    expect(menu.querySelector('.text-highlight') === null).to.be.true;
    expect(menu.querySelectorAll('.dropdown-item')[3].textContent).to.equal('Français');
  });

  it('keeps every character and the icon in place when highlighting flex items', async () => {
    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = '/typo3/sysext/backend/Resources/Public/Css/backend.css';
    await new Promise<void>(resolve => {
      stylesheet.addEventListener('load', () => resolve(), { once: true });
      root.prepend(stylesheet);
    });
    const style = document.createElement('style');
    style.textContent = '.dropdown-item-spaced { display: flex; gap: .5em; white-space: nowrap; align-items: center; }';
    root.prepend(style);
    const item = menu.querySelector<HTMLButtonElement>('.dropdown-item');
    item.classList.add('dropdown-item-spaced');
    item.innerHTML = '<span class="icon" aria-hidden="true" style="width:16px;height:16px;display:block"></span>Français français';
    await open(7);
    const positions = (): number[] => {
      const result: number[] = [];
      const walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT);
      while (walker.nextNode()) {
        const node = walker.currentNode as Text;
        for (let offset = 0; offset < node.length; offset++) {
          const range = document.createRange();
          range.setStart(node, offset);
          range.setEnd(node, offset + 1);
          result.push(range.getBoundingClientRect().x);
        }
      }
      return result;
    };
    const before = positions();
    const expectStablePositions = (): void => {
      const current = positions();
      expect(current.length).to.equal(before.length);
      // Splitting inline text may round font metrics to a fraction of a pixel.
      current.forEach((position, index) => expect(position).to.be.closeTo(before[index], .1));
    };
    const iconX = item.querySelector('.icon').getBoundingClientRect().x;
    filter('fr');
    expectStablePositions();
    expect(item.querySelector('.icon').getBoundingClientRect().x).to.equal(iconX);
    filter('ançais');
    expectStablePositions();
    filter('');
    expectStablePositions();
  });

  it('navigates only visible enabled items and retains native text editing', async () => {
    await open(7);
    filter('p');
    key(input, 'ArrowDown');
    expect(document.activeElement.textContent).to.equal('Português');
    input.focus();
    expect(key(input, 'Home').defaultPrevented).to.be.false;
    expect(key(input, 'ArrowLeft').defaultPrevented).to.be.false;
    expect(key(input, 'a', { ctrlKey: true }).defaultPrevented).to.be.false;
  });

  for (const count of [5, 7]) {
    it(`returns from a link to search and deletes the last character with ${count} options`, async () => {
      const original = menu.querySelectorAll('.dropdown-item')[3];
      const link = document.createElement('a');
      link.className = 'dropdown-item';
      link.href = '#francais';
      link.textContent = 'Français 👩‍💻';
      original.replaceWith(link);
      await open(count);
      if (count < 7) {
        key(trigger, 'f');
      }
      filter('fr');
      input.setSelectionRange(0, 1);
      key(input, 'ArrowDown');
      expect(document.activeElement === link).to.be.true;
      let clicks = 0;
      link.addEventListener('click', () => clicks++);

      expect(key(link, 'Backspace').defaultPrevented).to.be.true;
      expect(document.activeElement === input).to.be.true;
      expect(input.value).to.equal('f');
      expect(input.selectionStart).to.equal(1);
      expect(input.selectionEnd).to.equal(1);
      expect(menu.matches(':popover-open')).to.be.true;

      key(input, 'ArrowDown');
      key(link, 'r');
      expect(document.activeElement === input).to.be.true;
      expect(input.value).to.equal('fr');

      filter('Français 👩‍💻');
      key(input, 'ArrowDown');
      key(link, 'Backspace');
      expect(document.activeElement === input).to.be.true;
      expect(input.value).to.equal('Français ');
      expect(items().map(item => item.textContent)).to.eql(['Français 👩‍💻']);
      expect(clicks).to.equal(0);
    });
  }

  it('reveals empty search on Backspace from an unfiltered item', async () => {
    await open();
    key(trigger, 'ArrowDown');
    expect(document.activeElement === items()[0]).to.be.true;
    expect(key(document.activeElement as HTMLElement, 'Backspace').defaultPrevented).to.be.true;
    expect(input.hidden).to.be.false;
    expect(input.closest('li').hidden).to.be.false;
    expect(document.activeElement === input).to.be.true;
    expect(input.value).to.equal('');
    expect(items()).to.have.length(5);
    expect(menu.matches(':popover-open')).to.be.true;
  });

  it('preserves native Backspace editing and modified or composing key events', async () => {
    await open(7);
    filter('fr');
    key(input, 'ArrowDown');
    const item = document.activeElement as HTMLElement;
    for (const options of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { isComposing: true }]) {
      expect(key(item, 'Backspace', options).defaultPrevented).to.be.false;
      expect(document.activeElement === item).to.be.true;
      expect(input.value).to.equal('fr');
    }
    input.focus();
    input.setSelectionRange(0, 1);
    expect(key(input, 'Backspace').defaultPrevented).to.be.false;
    expect(input.selectionStart).to.equal(0);
    expect(input.selectionEnd).to.equal(1);
  });

  describe('with a sticky search field', () => {
    const expectVisible = (element: HTMLElement): void => {
      const bounds = menu.getBoundingClientRect();
      const top = Math.max(bounds.top + menu.clientTop, input.closest('li').getBoundingClientRect().bottom);
      const bottom = bounds.top + menu.clientTop + menu.clientHeight;
      const rect = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      const outline = Math.max(0, parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset));
      expect(rect.top - outline, element.textContent + ' is covered by search').to.be.at.least(top - .5);
      expect(rect.bottom + outline, element.textContent + ' is clipped below the menu').to.be.at.most(bottom + .5);
    };

    beforeEach(async () => {
      const stylesheet = document.createElement('link');
      stylesheet.rel = 'stylesheet';
      stylesheet.href = '/typo3/sysext/backend/Resources/Public/Css/backend.css';
      await new Promise<void>((resolve, reject) => {
        stylesheet.addEventListener('load', () => resolve(), { once: true });
        stylesheet.addEventListener('error', () => reject(new Error('Backend stylesheet failed to load')), { once: true });
        root.prepend(stylesheet);
      });
      const heading = document.createElement('li');
      heading.innerHTML = '<h6 class="dropdown-header">Languages</h6>';
      menu.querySelector('.dropdown-item').parentElement.before(heading);
      menu.style.maxHeight = '180px';
      await open(18);
    });

    for (const [height, query] of [[180, ''], [240, ''], [180, 'Zulu']] as const) {
      it(`keeps keyboard results below search and inside a ${height}px menu${query ? ' after filtering' : ''}`, () => {
        menu.style.maxHeight = height + 'px';
        filter(query);
        key(input, 'ArrowDown');
        expectVisible(document.activeElement as HTMLElement);
        for (const value of ['End', 'PageUp', 'ArrowUp', 'PageDown', 'Home']) {
          key(document.activeElement as HTMLElement, value);
          expectVisible(document.activeElement as HTMLElement);
        }
        expect(menu.scrollTop).to.equal(0);
        expect(menu.querySelector('.dropdown-header').getBoundingClientRect().top).to.be.at.least(input.closest('li').getBoundingClientRect().bottom);
      });
    }

    it('returns to visible search with ArrowUp and re-enters at the top with ArrowDown', () => {
      key(input, 'ArrowDown');
      key(document.activeElement as HTMLElement, 'End');
      for (let index = 0; index < 18; index++) {
        key(document.activeElement as HTMLElement, 'ArrowUp');
      }
      expect(document.activeElement === input).to.be.true;
      const bounds = menu.getBoundingClientRect();
      const rect = input.getBoundingClientRect();
      expect(rect.top).to.be.at.least(bounds.top);
      expect(rect.bottom).to.be.at.most(bounds.bottom);
      key(input, 'ArrowUp');
      expect(document.activeElement === input).to.be.true;
      key(input, 'ArrowDown');
      expect(document.activeElement === items()[0]).to.be.true;
      expectVisible(items()[0]);
      expect(menu.scrollTop).to.equal(0);
      menu.scrollTop = menu.scrollHeight;
      key(items()[0], 'Home');
      expect(menu.scrollTop).to.equal(0);
    });

    it('keeps programmatic focus visible below search and after resizing', () => {
      const enabled = items().filter(item => !item.disabled);
      enabled.at(-1).focus();
      enabled[5].focus();
      expect(document.activeElement === enabled[5]).to.be.true;
      expectVisible(enabled[5]);
      enabled.at(-1).focus();
      expectVisible(enabled.at(-1));
      menu.style.maxHeight = '140px';
      window.dispatchEvent(new Event('resize'));
      expectVisible(enabled.at(-1));
    });
  });

  for (const filtered of [false, true]) {
    it(`reveals the group heading when navigating to the first enabled ${filtered ? 'filtered ' : ''}menu item`, async () => {
      const source = Array.from(menu.querySelectorAll<HTMLButtonElement>('.dropdown-item'));
      source.forEach(item => item.textContent = 'Language ' + item.textContent);
      source[0].textContent = 'Other';
      const other = document.createElement('li');
      other.innerHTML = '<h6 class="dropdown-header">Other</h6>';
      source[0].parentElement.before(other);
      const languages = document.createElement('li');
      languages.innerHTML = '<h6 class="dropdown-header">Languages</h6>';
      source[1].parentElement.before(languages, source[2].parentElement);
      const style = document.createElement('style');
      style.textContent = '.dropdown-menu { max-height: 160px; overflow-y: auto; }'
        + '.dropdown-item { display: block; height: 32px; } .dropdown-header { height: 24px; }';
      root.prepend(style);
      await open(8);
      if (filtered) {
        filter('Language');
      }
      let clicks = 0;
      menu.addEventListener('click', () => clicks++);
      key(input, 'ArrowDown');
      key(document.activeElement as HTMLElement, 'End');
      expect(menu.scrollTop).to.be.greaterThan(0);
      key(document.activeElement as HTMLElement, 'Home');

      const first = items().find(item => !item.disabled);
      expect(document.activeElement === first).to.be.true;
      expect(menu.scrollTop).to.equal(0);
      const heading = filtered ? languages : other;
      expect(heading.getBoundingClientRect().top).to.be.at.least(menu.getBoundingClientRect().top);
      expect(clicks).to.equal(0);

      key(first, 'ArrowDown');
      key(document.activeElement as HTMLElement, 'ArrowUp');
      expect(menu.scrollTop).to.equal(0);
    });
  }

  for (const count of [5, 7]) {
    it(`keeps ArrowUp in search and returns from the first enabled result with ${count} options`, async () => {
      await open(count);
      if (count < 7) {
        key(trigger, 'p');
      } else {
        filter('p');
      }
      let clicks = 0;
      menu.addEventListener('click', () => clicks++);

      input.setSelectionRange(0, 1);
      expect(key(input, 'ArrowUp').defaultPrevented).to.be.true;
      expect(document.activeElement === input).to.be.true;
      expect(input.selectionStart).to.equal(0);
      expect(input.selectionEnd).to.equal(1);
      key(input, 'ArrowDown');
      expect(document.activeElement.textContent).to.equal('Português');
      expect(key(document.activeElement as HTMLElement, 'ArrowUp').defaultPrevented).to.be.true;
      expect(document.activeElement === input).to.be.true;
      expect(input.value).to.equal('p');
      expect(menu.matches(':popover-open')).to.be.true;
      expect(clicks).to.equal(0);

      filter('por');
      key(input, 'ArrowDown');
      expect(document.activeElement.textContent).to.equal('Português');
    });
  }

  it('keeps hidden search outside arrow navigation', async () => {
    await open();
    key(trigger, 'ArrowDown');
    const first = items()[0];
    expect(document.activeElement === first).to.be.true;
    key(first, 'ArrowUp');
    expect(document.activeElement === first).to.be.true;
    expect(input.closest('li').hidden).to.be.true;
  });

  it('activates the original action exactly once with Enter', async () => {
    await open(7);
    let clicks = 0;
    menu.querySelectorAll('.dropdown-item')[3].addEventListener('click', () => clicks++);
    filter('fr');
    key(input, 'Enter');
    expect(clicks).to.equal(1);
    expect(menu.matches(':popover-open')).to.be.false;
  });

  it('announces no matches, prevents submitting, and closes with Escape', async () => {
    await open(7);
    filter('missing');
    expect(items()).to.have.length(0);
    expect(menu.querySelector('[role="status"]').textContent).not.to.equal('');
    expect(key(input, 'Enter').defaultPrevented).to.be.true;
    expect(menu.matches(':popover-open')).to.be.true;
    key(input, 'Escape');
    expect(menu.matches(':popover-open')).to.be.false;
    expect(document.activeElement === trigger).to.be.true;
  });

  it('resets the query and temporary field on every close', async () => {
    await open();
    key(trigger, 'f');
    await toggle(() => menu.hidePopover());
    await open();
    expect(input.value).to.equal('');
    expect(input.closest('li').hidden).to.be.true;
    expect(items()).to.have.length(5);
    expect(menu.querySelectorAll('.searchable-select-search-row')).to.have.length(1);
  });

  it('does not trap Tab and closes when focus moves outside', async () => {
    await open(7);
    expect(key(input, 'Tab').defaultPrevented).to.be.false;
    root.querySelector<HTMLButtonElement>('#next').focus();
    expect(menu.matches(':popover-open')).to.be.false;
  });

  it('filters headings and dividers with their groups', async () => {
    const entries = document.createElement('template');
    entries.innerHTML = '<li><h6 class="dropdown-header">Other</h6></li><li><button class="dropdown-item">Italiano</button></li><li><hr class="dropdown-divider"></li>';
    menu.prepend(entries.content);
    await open(7);
    filter('French');
    expect(menu.querySelector('.dropdown-header').parentElement.hidden).to.be.true;
    expect(menu.querySelector('.dropdown-divider').parentElement.hidden).to.be.true;
    filter('Italiano');
    expect(menu.querySelector('.dropdown-header').parentElement.hidden).to.be.false;
    expect(menu.querySelector('.dropdown-divider').parentElement.hidden).to.be.true;
  });

  it('preserves normal dropdowns and exposes accessible search and results', async () => {
    await open(7);
    filter('fr');
    await expect(root).to.be.accessible();
    await toggle(() => menu.hidePopover());
    const component = menu.closest('typo3-backend-searchable-select');
    component.replaceWith(component.firstElementChild);
    trigger.focus();
    expect(key(trigger, 'f').defaultPrevented).to.be.false;
    expect(menu.matches(':popover-open')).to.be.false;
  });
});
