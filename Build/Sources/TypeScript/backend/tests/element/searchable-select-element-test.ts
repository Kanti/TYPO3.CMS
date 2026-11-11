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

import '@typo3/backend/element/searchable-select-element.js';
import type { SearchableSelectElement } from '@typo3/backend/element/searchable-select-element.js';
import Icons from '@typo3/backend/icons.js';
import { expect, fixtureSync, html } from '@open-wc/testing';
import { stub, type SinonStub } from 'sinon';
import type { } from 'mocha';

// Load browser commands without adding their server-side Node types to the build.
const commandsModule = '@web/test-runner-commands';
const { resetMouse, sendMouse } = await import(commandsModule);

describe('@typo3/backend/element/searchable-select-element', () => {
  let root: HTMLFormElement;
  let component: SearchableSelectElement;
  let select: HTMLSelectElement;
  let trigger: HTMLButtonElement;
  let input: HTMLInputElement;
  let getIconStub: SinonStub;

  const settle = async (): Promise<void> => {
    await component.updateComplete;
    await component.updateComplete;
  };
  const key = async (target: HTMLElement, value: string): Promise<void> => {
    target.dispatchEvent(new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true }));
    await settle();
  };
  const filter = async (value: string): Promise<void> => {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
  };
  const items = (): HTMLElement[] => Array.from(component.querySelectorAll('[role="option"]'));

  afterEach(async () => {
    await resetMouse();
    getIconStub.restore();
  });

  beforeEach(async () => {
    getIconStub = stub(Icons, 'getIcon').resolves('<span class="icon icon-size-small" aria-hidden="true"></span>');
    root = fixtureSync(html`
      <form>
        <label for="language">Language</label>
        <typo3-backend-searchable-select>
          <select id="language" name="language" class="form-select" required>
            <option value="en">English</option>
            <option value="de" selected>Deutsch</option>
            <option value="es" disabled>Español</option>
            <optgroup label="Other">
              <option value="fr">Français</option>
              <option value="pt">Português</option>
            </optgroup>
            <optgroup label="Unavailable" disabled>
              <option value="it">Italiano</option>
            </optgroup>
          </select>
        </typo3-backend-searchable-select>
        <button type="button">Next</button>
      </form>
    `);
    component = root.querySelector('typo3-backend-searchable-select');
    await settle();
    select = component.querySelector('select');
    trigger = component.querySelector('button');
    input = component.querySelector('input');
    trigger.focus();
  });

  for (const search of ['hidden', 'visible', 'filtered']) {
    it(`selects an option with a real mouse click when search is ${search}`, async () => {
      const stylesheet = document.createElement('link');
      stylesheet.rel = 'stylesheet';
      stylesheet.href = '/typo3/sysext/backend/Resources/Public/Css/backend.css';
      await new Promise<void>(resolve => {
        stylesheet.addEventListener('load', () => resolve(), { once: true });
        root.prepend(stylesheet);
      });
      if (search === 'visible') {
        select.add(new Option('Zulu', 'zu'));
      }
      trigger.click();
      await settle();
      if (search === 'filtered') {
        await key(trigger, 'f');
        await filter('fr');
      }
      let inputs = 0;
      let changes = 0;
      select.addEventListener('input', () => inputs++);
      root.addEventListener('change', () => changes++);
      const option = items().find(item => item.querySelector('.searchable-select-option-label').textContent === 'Français');
      const rect = option.getBoundingClientRect();
      await sendMouse({ type: 'move', position: [Math.round(rect.x + rect.width / 2), Math.round(rect.y + rect.height / 2)] });
      await sendMouse({ type: 'down' });
      await settle();
      expect(component.querySelector('.searchable-select-popup').matches(':popover-open')).to.be.true;
      expect(document.activeElement === (search === 'hidden' ? trigger : input)).to.be.true;
      await sendMouse({ type: 'up' });
      await settle();

      expect(select.value).to.equal('fr');
      expect(new FormData(root).get('language')).to.equal('fr');
      expect(trigger.textContent).to.equal('Français');
      expect(inputs).to.equal(1);
      expect(changes).to.equal(1);
      expect(trigger.getAttribute('aria-expanded')).to.equal('false');
      expect(document.activeElement === trigger).to.be.true;
    });
  }

  it('shows and focuses search automatically for seven options', async () => {
    select.add(new Option('Zulu', 'zu'));
    trigger.click();
    await settle();
    expect(input.hidden).to.be.false;
    expect(document.activeElement === input).to.be.true;
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
    expect(input.type).to.equal('search');
    expect(input.getAttribute('aria-controls')).to.equal(component.querySelector('[role="listbox"]').id);
  });

  for (const filtered of [false, true]) {
    it(`reveals the group heading when navigating to the first enabled ${filtered ? 'filtered ' : ''}option`, async () => {
      const other = document.createElement('optgroup');
      other.label = 'Other';
      other.append(new Option('Other', 'other'));
      const languages = document.createElement('optgroup');
      languages.label = 'Languages';
      const hidden = new Option('Language hidden', 'hidden');
      hidden.hidden = true;
      const disabled = new Option('Language unavailable', 'unavailable');
      disabled.disabled = true;
      languages.append(hidden, disabled);
      for (let index = 1; index <= 10; index++) {
        languages.append(new Option(`Language ${index}`, `language-${index}`));
      }
      select.replaceChildren(other, languages);
      select.value = 'language-2';
      const style = document.createElement('style');
      style.textContent = '.searchable-select-options { max-height: 160px; overflow-y: auto; }'
        + '.searchable-select-option { height: 32px; } .searchable-select-group-label { height: 24px; }';
      root.prepend(style);
      trigger.click();
      await settle();
      if (filtered) {
        await filter('Language');
      }
      const listbox = component.querySelector<HTMLElement>('[role="listbox"]');
      await key(input, 'PageDown');
      expect(listbox.scrollTop).to.be.greaterThan(0);
      await key(input, 'PageUp');

      const first = items().find(item => item.getAttribute('aria-disabled') !== 'true');
      expect(input.getAttribute('aria-activedescendant')).to.equal(first.id);
      expect(listbox.scrollTop).to.equal(0);
      const heading = first.parentElement.querySelector('.searchable-select-group-label');
      expect(heading.getBoundingClientRect().top).to.be.at.least(listbox.getBoundingClientRect().top);
      expect(document.activeElement === input).to.be.true;
      expect(select.value).to.equal('language-2');

      await key(input, 'ArrowDown');
      await key(input, 'ArrowUp');
      expect(listbox.scrollTop).to.equal(0);
      listbox.scrollTop = listbox.scrollHeight;
      await key(input, 'ArrowUp');
      expect(listbox.scrollTop).to.equal(0);
    });
  }

  describe('with a scrolling option list', () => {
    let listbox: HTMLElement;

    const expectActiveVisible = (): void => {
      const active = component.querySelector<HTMLElement>('[data-active]');
      const rect = active.getBoundingClientRect();
      const bounds = listbox.getBoundingClientRect();
      expect(rect.top).to.be.at.least(bounds.top + listbox.clientTop - .5);
      expect(rect.bottom).to.be.at.most(bounds.top + listbox.clientTop + listbox.clientHeight + .5);
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
      const group = document.createElement('optgroup');
      group.label = 'Languages';
      for (let index = 1; index <= 20; index++) {
        group.append(new Option(`Language ${index}`, `language-${index}`));
      }
      select.replaceChildren(group);
      select.value = 'language-20';
      listbox = component.querySelector('[role="listbox"]');
      listbox.style.maxHeight = '160px';
      trigger.click();
      await settle();
    });

    it('scrolls the selected option into view when opening and navigates back to the group heading', async () => {
      expectActiveVisible();
      expect(listbox.scrollTop).to.be.greaterThan(0);
      for (let index = 0; index < 3; index++) {
        await key(input, 'PageUp');
        expectActiveVisible();
      }
      expect(listbox.scrollTop).to.equal(0);
      expect(component.querySelector('.searchable-select-group-label').getBoundingClientRect().top).to.be.at.least(listbox.getBoundingClientRect().top);
      expect(document.activeElement === input).to.be.true;
      expect(select.value).to.equal('language-20');
    });

    it('keeps the active option fully visible after the list gets shorter', async () => {
      await key(input, 'PageUp');
      await key(input, 'PageDown');
      expectActiveVisible();
      listbox.style.maxHeight = '80px';
      window.dispatchEvent(new Event('resize'));
      expectActiveVisible();
      expect(document.activeElement === input).to.be.true;
    });
  });

  it('marks only the selected option with the TYPO3 check icon hidden from screen readers', async () => {
    trigger.click();
    await settle();
    const checkSelection = (value: string): void => {
      const selected = items().filter(item => item.getAttribute('aria-selected') === 'true');
      expect(selected).to.have.length(1);
      expect(selected[0].querySelector('.searchable-select-option-label').textContent).to.equal(value);
      const indicator = selected[0].querySelector('.searchable-select-option-indicator');
      expect(indicator.getAttribute('aria-hidden')).to.equal('true');
      const icon = indicator.querySelector('typo3-backend-icon');
      expect(icon === null).to.be.false;
      expect(icon.getAttribute('identifier')).to.equal('actions-check');
      expect(icon.getAttribute('size')).to.equal('small');
      expect(customElements.get('typo3-backend-icon') !== undefined).to.be.true;
      expect(component.querySelectorAll('.searchable-select-option-indicator')).to.have.length(1);
    };
    checkSelection('Deutsch');
    items().find(item => item.textContent.includes('Français')).click();
    await settle();
    expect(select.value).to.equal('fr');
    checkSelection('Français');
  });

  it('reveals search on typing and includes the first character', async () => {
    trigger.click();
    await settle();
    expect(input.hidden).to.be.true;
    await key(trigger, 'f');
    expect(input.hidden).to.be.false;
    expect(input.value).to.equal('f');
    expect(document.activeElement === input).to.be.true;
    expect(items().map(item => item.querySelector('.searchable-select-option-label').textContent)).to.eql(['Français']);
    expect(select.value).to.equal('de');
    expect(input.getAttribute('aria-activedescendant')).to.equal(items()[0].id);
  });

  it('focuses the trigger after clicking from outside and reveals search while typing', async () => {
    root.querySelector<HTMLButtonElement>(':scope > button').focus();
    await settle();
    trigger.click();
    await settle();
    expect(input.hidden).to.be.true;
    expect(document.activeElement === trigger).to.be.true;

    for (const character of 'fr') {
      const target = document.activeElement as HTMLElement;
      const event = new KeyboardEvent('keydown', { key: character, bubbles: true, cancelable: true });
      if (target.dispatchEvent(event) && target === input) {
        await filter(input.value + character);
      }
      await settle();
    }
    expect(input.hidden).to.be.false;
    expect(document.activeElement === input).to.be.true;
    expect(input.value).to.equal('fr');
    expect(items().map(item => item.querySelector('.searchable-select-option-label').textContent)).to.eql(['Français']);
    expect(select.value).to.equal('de');
  });

  it('opens and searches when typing on the closed trigger', async () => {
    await key(trigger, 'p');
    expect(input.value).to.equal('p');
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
    expect(items().map(item => item.querySelector('.searchable-select-option-label').textContent)).to.eql(['Español', 'Português']);
  });

  for (const opened of [false, true]) {
    it(`keeps both characters and focuses search when typing before the ${opened ? 'open' : 'closed'} select has rendered`, async () => {
      if (opened) {
        trigger.click();
        await settle();
      }
      expect(input.hidden).to.be.true;
      for (const character of 'fr') {
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: character, bubbles: true, cancelable: true }));
      }
      await settle();

      expect(input.hidden).to.be.false;
      expect(input.value).to.equal('fr');
      expect(document.activeElement === input).to.be.true;
      expect(input.selectionStart).to.equal(2);
      expect(items().map(item => item.querySelector('.searchable-select-option-label').textContent)).to.eql(['Français']);
      expect(select.value).to.equal('de');
    });
  }

  it('filters accents and case and announces empty results without stale focus', async () => {
    select.add(new Option('Zulu', 'zu'));
    trigger.click();
    await settle();
    await filter('FRANCAIS');
    expect(items().map(item => item.querySelector('.searchable-select-option-label').textContent)).to.eql(['Français']);
    await filter('zzzz');
    expect(items()).to.have.length(0);
    expect(input.hasAttribute('aria-activedescendant')).to.be.false;
    expect(component.querySelector('[role="status"]').textContent).not.to.equal('');
    await key(input, 'Enter');
    expect(select.value).to.equal('de');
    await filter('');
    expect(items()).to.have.length(7);
  });

  it('clears only the search, restores all options and keeps focus in the field', async () => {
    const clear = component.querySelector<HTMLButtonElement>('.searchable-select-clear');
    expect(clear.hidden).to.be.true;
    let changes = 0;
    root.addEventListener('change', () => changes++);
    await key(trigger, 'f');
    expect(clear.hidden).to.be.false;
    expect(clear.getAttribute('aria-label')).not.to.equal('');
    clear.focus();
    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    clear.dispatchEvent(enter);
    expect(enter.defaultPrevented).to.be.false;
    clear.click();
    await settle();

    expect(input.value).to.equal('');
    expect(document.activeElement === input).to.be.true;
    expect(clear.hidden).to.be.true;
    expect(items()).to.have.length(6);
    expect(component.querySelector('.text-highlight') === null).to.be.true;
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
    expect(new FormData(root).get('language')).to.equal('de');
    expect(changes).to.equal(0);
    await expect(root).to.be.accessible();
  });

  it('skips disabled options and commits once with native events', async () => {
    let inputs = 0;
    let changes = 0;
    select.addEventListener('input', () => inputs++);
    root.addEventListener('change', () => changes++);
    trigger.click();
    await settle();
    await key(trigger, 'ArrowDown');
    await key(trigger, 'Enter');
    expect(select.value).to.equal('fr');
    expect(new FormData(root).get('language')).to.equal('fr');
    expect(inputs).to.equal(1);
    expect(changes).to.equal(1);
    expect(document.activeElement === trigger).to.be.true;
  });

  it('does not select disabled optgroups or submit on Enter with no match', async () => {
    await key(trigger, 'i');
    expect(items()).to.have.length(3);
    await filter('Italiano');
    expect(items()[0].getAttribute('aria-disabled')).to.equal('true');
    items()[0].click();
    await key(input, 'Enter');
    expect(select.value).to.equal('de');
  });

  it('Escape cancels and reopening resets the query and transient search', async () => {
    await key(trigger, 'f');
    await key(input, 'Escape');
    expect(select.value).to.equal('de');
    expect(document.activeElement === trigger).to.be.true;
    expect(trigger.getAttribute('aria-expanded')).to.equal('false');
    trigger.click();
    await settle();
    expect(input.hidden).to.be.true;
    expect(input.value).to.equal('');
    expect(items()).to.have.length(6);
  });

  it('Tab closes without selecting or trapping focus', async () => {
    await key(trigger, 'f');
    const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    input.dispatchEvent(event);
    await settle();
    expect(event.defaultPrevented).to.be.false;
    expect(trigger.getAttribute('aria-expanded')).to.equal('false');
    expect(select.value).to.equal('de');
  });

  it('forwards focus and supports programmatic changes, disabled state and form reset', async () => {
    root.querySelector<HTMLButtonElement>(':scope > button').focus();
    select.dispatchEvent(new FocusEvent('focus'));
    expect(document.activeElement === trigger).to.be.true;
    select.value = 'fr';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(trigger.textContent).to.equal('Français');
    select.disabled = true;
    await new Promise<void>(resolve => queueMicrotask(resolve));
    await settle();
    expect(trigger.disabled).to.be.true;
    select.disabled = false;
    root.reset();
    await new Promise<void>(resolve => queueMicrotask(resolve));
    await settle();
    expect(trigger.textContent).to.equal('Deutsch');
  });

  it('exposes accessible names and selected state', async () => {
    trigger.click();
    await settle();
    expect(trigger.getAttribute('aria-label')).to.include('Language');
    expect(items()[1].getAttribute('aria-selected')).to.equal('true');
    await expect(root).to.be.accessible();
    await key(trigger, 'f');
    await expect(root).to.be.accessible();
  });

  it('reconnects without duplicate controls or listeners', async () => {
    component.remove();
    root.prepend(component);
    await new Promise<void>(resolve => queueMicrotask(resolve));
    await settle();
    expect(component.querySelectorAll('.searchable-select-trigger')).to.have.length(1);
    expect(component.querySelectorAll('.searchable-select-clear')).to.have.length(1);
    trigger = component.querySelector('button');
    trigger.click();
    await settle();
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
  });
});

describe('@typo3/backend/element/searchable-select-element with primary touch input', () => {
  let root: HTMLFormElement;
  let component: SearchableSelectElement;
  let select: HTMLSelectElement;
  let originalMarkup: string;
  let matchMediaStub: SinonStub;

  beforeEach(async () => {
    const matchMedia = window.matchMedia.bind(window);
    matchMediaStub = stub(window, 'matchMedia').callsFake((query: string): MediaQueryList => {
      const result = matchMedia(query);
      if (query === '(pointer: coarse) and (hover: none)') {
        Object.defineProperty(result, 'matches', { value: true });
      }
      return result;
    });
    root = fixtureSync(html`
      <form>
        <label for="touch-language">Language</label>
        <select id="touch-language" name="language" class="form-select" size="1"
          tabindex="3" aria-hidden="false" aria-describedby="touch-help" required>
          <option value="en">English</option>
          <option value="de" selected>Deutsch</option>
          <option value="fr">Français</option>
          <option value="pt">Português</option>
          <option value="es">Español</option>
          <option value="it">Italiano</option>
          <option value="zu">Zulu</option>
        </select>
        <span id="touch-help">Choose a language</span>
      </form>
    `);
    select = root.querySelector('select');
    originalMarkup = select.outerHTML;
    component = document.createElement('typo3-backend-searchable-select');
    select.replaceWith(component);
    component.append(select);
    await component.updateComplete;
    await component.updateComplete;

    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = '/typo3/sysext/backend/Resources/Public/Css/backend.css';
    await new Promise<void>((resolve, reject) => {
      stylesheet.addEventListener('load', () => resolve(), { once: true });
      stylesheet.addEventListener('error', () => reject(new Error('Backend stylesheet failed to load')), { once: true });
      root.prepend(stylesheet);
    });
  });

  afterEach(() => matchMediaStub.restore());

  for (const reconnect of [false, true]) {
    it(`keeps the native select visible and unchanged${reconnect ? ' after reconnecting' : ''}`, async () => {
      if (reconnect) {
        component.remove();
        root.prepend(component);
        await new Promise<void>(resolve => queueMicrotask(resolve));
        await component.updateComplete;
        await component.updateComplete;
      }
      expect(select.outerHTML).to.equal(originalMarkup);
      expect(select.getBoundingClientRect().width > 1).to.be.true;
      expect(getComputedStyle(select).clipPath).to.equal('none');
      expect(component.querySelectorAll('.searchable-select-trigger, .searchable-select-popup, input')).to.have.length(0);
      select.focus();
      expect(document.activeElement === select).to.be.true;
      let bubbled = 0;
      root.addEventListener('keydown', () => bubbled++);
      const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', 'Escape', 'f', 'Tab'];
      for (const key of keys) {
        const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
        select.dispatchEvent(event);
        expect(event.defaultPrevented).to.be.false;
      }
      expect(bubbled).to.equal(keys.length);
      expect(select.outerHTML).to.equal(originalMarkup);
      expect(new FormData(root).get('language')).to.equal('de');
    });
  }
});
