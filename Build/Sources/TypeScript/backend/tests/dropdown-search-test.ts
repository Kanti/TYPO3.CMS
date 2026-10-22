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
import { expect, fixture, html, nextFrame } from '@open-wc/testing';
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
  const open = async (count: number = 5): Promise<void> => {
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
      menu.insertBefore(row, menu.querySelector('.dropdown-search-status'));
    }
    trigger.focus();
    menu.showPopover();
    await nextFrame();
    input = menu.querySelector('input');
  };
  const filter = (value: string): void => {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };

  beforeEach(async () => {
    root = await fixture(html`
      <div>
        <div class="dropdown">
          <button type="button" class="dropdown-toggle" popovertarget="search-menu">Languages</button>
          <ul id="search-menu" class="dropdown-menu" popover data-dropdown-search>
            <li><button type="button" class="dropdown-item">English</button></li>
            <li><button type="button" class="dropdown-item">Deutsch</button></li>
            <li><button type="button" class="dropdown-item" disabled>Español</button></li>
            <li><button type="button" class="dropdown-item">Français</button></li>
            <li><button type="button" class="dropdown-item">Português</button></li>
            <li hidden><button type="button" class="dropdown-item">Hidden</button></li>
          </ul>
        </div>
        <button type="button" id="next">Next</button>
      </div>
    `);
    trigger = root.querySelector('.dropdown-toggle');
    menu = root.querySelector('.dropdown-menu');
  });

  it('focuses search immediately when configured', async () => {
    await open(7);
    expect(input.closest('li').hidden).to.be.false;
    expect(document.activeElement).to.equal(input);
    expect(input.type).to.equal('search');
    expect(input.getAttribute('aria-label')).not.to.equal('');
  });

  it('shows search starting at seven options and restores it after filtering', async () => {
    await open(6);
    expect(input.closest('li').hidden).to.be.true;
    menu.hidePopover();
    await nextFrame();
    await open(7);
    expect(input.closest('li').hidden).to.be.false;
    filter('Deutsch');
    menu.hidePopover();
    await nextFrame();
    await open(7);
    expect(input.closest('li').hidden).to.be.false;
    expect(items()).to.have.length(7);
  });

  it('reveals search on typing and preserves the first character', async () => {
    await open();
    expect(input.closest('li').hidden).to.be.true;
    key(trigger, 'f');
    expect(input.value).to.equal('f');
    expect(document.activeElement).to.equal(input);
    expect(items().map(item => item.textContent)).to.eql(['Français']);
  });

  it('opens and searches on a closed trigger', async () => {
    trigger.focus();
    key(trigger, 'p');
    await nextFrame();
    input = menu.querySelector('input');
    expect(menu.matches(':popover-open')).to.be.true;
    expect(input.value).to.equal('p');
    expect(document.activeElement).to.equal(input);
  });

  it('filters case and accents without revealing originally hidden entries', async () => {
    await open(7);
    filter('FRANCAIS');
    expect(items().map(item => item.textContent)).to.eql(['Français']);
    filter('');
    expect(items()).to.have.length(7);
    expect(menu.querySelector('[role="status"]').textContent).to.equal('');
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
    expect(icon.querySelector('.text-highlight')).to.equal(null);
    expect(item.querySelector('strong')).to.equal(strong);
    filter('<img');
    expect(item.querySelector('.text-highlight').textContent).to.equal('<img');
    expect(item.querySelector('img')).to.equal(null);
    key(input, 'Enter');
    expect(clicks).to.equal(1);
    expect(item.querySelector('strong')).to.equal(strong);
    expect(item.querySelector('.icon')).to.equal(icon);
    expect(item.querySelector('.text-highlight')).to.equal(null);
  });

  it('updates highlights and removes them on clearing or closing', async () => {
    await open(7);
    filter('fr');
    expect(menu.querySelector('.text-highlight').textContent).to.equal('Fr');
    filter('de');
    expect(Array.from(menu.querySelectorAll('.text-highlight')).map(mark => mark.textContent)).to.eql(['De']);
    filter('');
    expect(menu.querySelector('.text-highlight')).to.equal(null);
    filter('fr');
    menu.hidePopover();
    expect(menu.querySelector('.text-highlight')).to.equal(null);
    expect(menu.querySelectorAll('.dropdown-item')[3].textContent).to.equal('Français');
  });

  it('keeps every character and the icon in place when highlighting flex items', async () => {
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
    key(input, 'ArrowUp');
    expect(document.activeElement.textContent).to.equal('Português');
    input.focus();
    expect(key(input, 'Home').defaultPrevented).to.be.false;
    expect(key(input, 'ArrowLeft').defaultPrevented).to.be.false;
    expect(key(input, 'a', { ctrlKey: true }).defaultPrevented).to.be.false;
  });

  it('activates the original action exactly once with Enter', async () => {
    await open(7);
    let clicks = 0;
    menu.querySelectorAll('button')[3].addEventListener('click', () => clicks++);
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
    expect(document.activeElement).to.equal(trigger);
  });

  it('resets the query and temporary field on every close', async () => {
    await open();
    key(trigger, 'f');
    menu.hidePopover();
    await nextFrame();
    await open();
    expect(input.value).to.equal('');
    expect(input.closest('li').hidden).to.be.true;
    expect(items()).to.have.length(5);
    expect(menu.querySelectorAll('.dropdown-search')).to.have.length(1);
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
    menu.hidePopover();
    await nextFrame();
    menu.removeAttribute('data-dropdown-search');
    trigger.focus();
    expect(key(trigger, 'f').defaultPrevented).to.be.false;
    expect(menu.matches(':popover-open')).to.be.false;
  });
});
