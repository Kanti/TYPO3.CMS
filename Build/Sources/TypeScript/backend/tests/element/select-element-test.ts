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

import { SelectElement } from '@typo3/backend/element/select-element.js';
import { expect, fixture, html } from '@open-wc/testing';
import type { } from 'mocha';

describe('@typo3/backend/element/select-element', () => {
  let root: HTMLFormElement;
  let component: SelectElement;
  let select: HTMLSelectElement;
  let trigger: HTMLButtonElement;
  let input: HTMLInputElement;

  const key = (target: HTMLElement, value: string): void => {
    target.dispatchEvent(new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true }));
  };
  const filter = (value: string): void => {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };
  const items = (): HTMLElement[] => Array.from(component.querySelectorAll('[role="option"]'));

  beforeEach(async () => {
    root = await fixture(html`
      <form>
        <label for="language">Language</label>
        <typo3-backend-select>
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
        </typo3-backend-select>
        <button type="button">Next</button>
      </form>
    `);
    component = root.querySelector('typo3-backend-select');
    select = component.querySelector('select');
    trigger = component.querySelector('button');
    input = component.querySelector('input');
    trigger.focus();
  });

  it('shows and focuses search automatically for seven options', () => {
    select.add(new Option('Zulu', 'zu'));
    trigger.click();
    expect(input.hidden).to.be.false;
    expect(document.activeElement).to.equal(input);
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
    expect(input.type).to.equal('search');
    expect(input.getAttribute('aria-controls')).to.equal(component.querySelector('[role="listbox"]').id);
  });

  it('reveals search on typing and includes the first character', () => {
    trigger.click();
    expect(input.hidden).to.be.true;
    key(trigger, 'f');
    expect(input.hidden).to.be.false;
    expect(input.value).to.equal('f');
    expect(document.activeElement).to.equal(input);
    expect(items().map(item => item.textContent)).to.eql(['Français']);
    expect(select.value).to.equal('de');
    expect(input.getAttribute('aria-activedescendant')).to.equal(items()[0].id);
  });

  it('opens and searches when typing on the closed trigger', () => {
    key(trigger, 'p');
    expect(input.value).to.equal('p');
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
    expect(items().map(item => item.textContent)).to.eql(['Español', 'Português']);
  });

  it('filters accents and case and announces empty results without stale focus', () => {
    select.add(new Option('Zulu', 'zu'));
    trigger.click();
    filter('FRANCAIS');
    expect(items().map(item => item.textContent)).to.eql(['Français']);
    filter('zzzz');
    expect(items()).to.have.length(0);
    expect(input.hasAttribute('aria-activedescendant')).to.be.false;
    expect(component.querySelector('[role="status"]').textContent).not.to.equal('');
    key(input, 'Enter');
    expect(select.value).to.equal('de');
    filter('');
    expect(items()).to.have.length(7);
  });

  it('skips disabled options and commits once with native events', () => {
    let inputs = 0;
    let changes = 0;
    select.addEventListener('input', () => inputs++);
    root.addEventListener('change', () => changes++);
    trigger.click();
    key(trigger, 'ArrowDown');
    key(trigger, 'Enter');
    expect(select.value).to.equal('fr');
    expect(new FormData(root).get('language')).to.equal('fr');
    expect(inputs).to.equal(1);
    expect(changes).to.equal(1);
    expect(document.activeElement).to.equal(trigger);
  });

  it('does not select disabled optgroups or submit on Enter with no match', () => {
    key(trigger, 'i');
    expect(items()).to.have.length(3);
    filter('Italiano');
    expect(items()[0].getAttribute('aria-disabled')).to.equal('true');
    items()[0].click();
    key(input, 'Enter');
    expect(select.value).to.equal('de');
  });

  it('Escape cancels and reopening resets the query and transient search', () => {
    key(trigger, 'f');
    key(input, 'Escape');
    expect(select.value).to.equal('de');
    expect(document.activeElement).to.equal(trigger);
    expect(trigger.getAttribute('aria-expanded')).to.equal('false');
    trigger.click();
    expect(input.hidden).to.be.true;
    expect(input.value).to.equal('');
    expect(items()).to.have.length(6);
  });

  it('Tab closes without selecting or trapping focus', () => {
    key(trigger, 'f');
    const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    input.dispatchEvent(event);
    expect(event.defaultPrevented).to.be.false;
    expect(trigger.getAttribute('aria-expanded')).to.equal('false');
    expect(select.value).to.equal('de');
  });

  it('supports labels, programmatic changes, disabled state and form reset', async () => {
    root.querySelector('label').click();
    expect(document.activeElement).to.equal(trigger);
    select.value = 'fr';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    expect(trigger.textContent).to.equal('Français');
    select.disabled = true;
    await new Promise<void>(resolve => queueMicrotask(resolve));
    expect(trigger.disabled).to.be.true;
    select.disabled = false;
    root.reset();
    await new Promise<void>(resolve => queueMicrotask(resolve));
    expect(trigger.textContent).to.equal('Deutsch');
  });

  it('exposes accessible names and selected state', async () => {
    trigger.click();
    expect(trigger.getAttribute('aria-label')).to.include('Language');
    expect(items()[1].getAttribute('aria-selected')).to.equal('true');
    await expect(root).to.be.accessible();
    key(trigger, 'f');
    await expect(root).to.be.accessible();
  });

  it('enhances a native FormEngine control without changing its parent or form events', async () => {
    component.remove();
    const group = document.createElement('div');
    group.className = 'input-group';
    const icon = document.createElement('span');
    icon.className = 'input-group-icon';
    group.append(icon, select);
    root.prepend(group);
    SelectElement.enhance(select);
    SelectElement.enhance(select);
    await new Promise<void>(resolve => queueMicrotask(resolve));
    component = group.querySelector('typo3-backend-select');
    trigger = component.querySelector('button');
    input = component.querySelector('input');
    expect(select.parentElement).to.equal(group);
    expect(select.parentElement.querySelector('.input-group-icon')).to.equal(icon);
    expect(group.querySelectorAll('typo3-backend-select')).to.have.length(1);
    let changes = 0;
    select.addEventListener('change', () => changes++);
    key(trigger, 'f');
    expect(component.querySelector('.text-highlight').textContent).to.equal('F');
    key(input, 'Enter');
    expect(select.value).to.equal('fr');
    expect(new FormData(root).get('language')).to.equal('fr');
    expect(changes).to.equal(1);
  });

  it('preserves listbox and multiple-select controls', () => {
    const listbox = document.createElement('select');
    listbox.size = 5;
    const multiple = document.createElement('select');
    multiple.multiple = true;
    root.append(listbox, multiple);
    SelectElement.enhance(listbox);
    SelectElement.enhance(multiple);
    expect(root.querySelectorAll('typo3-backend-select')).to.have.length(1);
  });

  it('reconnects without duplicate controls or listeners', async () => {
    component.remove();
    root.prepend(component);
    await new Promise<void>(resolve => queueMicrotask(resolve));
    expect(component.querySelectorAll('button')).to.have.length(1);
    trigger = component.querySelector('button');
    trigger.click();
    expect(trigger.getAttribute('aria-expanded')).to.equal('true');
  });
});
