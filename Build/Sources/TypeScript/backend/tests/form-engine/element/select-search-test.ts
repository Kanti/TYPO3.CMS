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
import { expect, fixtureSync, html } from '@open-wc/testing';
import * as sinon from 'sinon';
import type { } from 'mocha';

if (!window.TYPO3) {
  Object.assign(window, { TYPO3: { settings: {} } });
}
const [{ default: SelectSingleElement }, { default: SelectCountryElement }, { default: FormEngine }] = await Promise.all([
  import('@typo3/backend/form-engine/element/select-single-element.js'),
  import('@typo3/backend/form-engine/element/select-country-element.js'),
  import('@typo3/backend/form-engine.js'),
]);

for (const [name, initializer] of [['selectSingle', SelectSingleElement], ['selectCountry', SelectCountryElement]] as const) {
  describe(`FormEngine ${name} search`, () => {
    afterEach(() => sinon.restore());

    it('updates the original field, icon and onChange actions exactly once', async () => {
      const root = fixtureSync<HTMLElement>(html`
        <form class="t3js-formengine-field-item">
          <div class="form-control-wrap">
            <label for="formengine-select">Language</label>
            <div class="input-group">
              <span class="input-group-icon">de-icon</span>
              <typo3-backend-searchable-select><select id="formengine-select" name="language" class="form-select">
                <option value="en">English</option>
                <option value="de" selected>Deutsch</option>
                <option value="fr" data-icon="&lt;span&gt;fr-icon&lt;/span&gt;">Français</option>
              </select></typo3-backend-searchable-select>
            </div>
          </div>
        </form>
      `);
      const onChange = sinon.stub(FormEngine, 'processOnFieldChange');
      initializer.initialize('#formengine-select', { onChange: [] });
      await new Promise<void>(resolve => queueMicrotask(resolve));
      const component = root.querySelector('typo3-backend-searchable-select');
      await component.updateComplete;
      const trigger = component.querySelector('button');
      trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'f', bubbles: true, cancelable: true }));
      await component.updateComplete;
      await component.updateComplete;
      expect(component.querySelector('.text-highlight').textContent).to.equal('F');
      component.querySelector<HTMLElement>('[role="option"]').click();
      expect(root.querySelector<HTMLSelectElement>('select').value).to.equal('fr');
      expect(new FormData(root as HTMLFormElement).get('language')).to.equal('fr');
      expect(root.querySelector('.input-group-icon').textContent).to.equal('fr-icon');
      expect(onChange.calledOnce).to.be.true;
    });
  });
}

describe('FormEngine selectSingle icon updates', () => {
  let root: HTMLElement;

  beforeEach((): void => {
    root = document.createElement('div');
    document.body.appendChild(root);
  });

  afterEach((): void => {
    root.remove();
    root = null;
  });

  function render(select: string): HTMLSelectElement {
    root.innerHTML = `
      <div class="t3js-formengine-field-item">
        <div class="form-control-wrap">
          <div class="input-group">
            <span class="input-group-text input-group-icon"><span>first-icon</span></span>
            ${select}
          </div>
        </div>
      </div>
    `;
    SelectSingleElement.initialize('#select', {});
    return root.querySelector('#select');
  }

  const options = `
    <option value="first" data-icon="&lt;span&gt;first-icon&lt;/span&gt;">First</option>
    <option value="second" data-icon="&lt;span&gt;second-icon&lt;/span&gt;">Second</option>
  `;

  it('updates the prepended icon of the selected option', () => {
    const select = render(`<select id="select">${options}</select>`);
    select.value = 'second';
    select.dispatchEvent(new Event('change'));

    expect(root.querySelector('.input-group-icon').innerHTML).to.equal('<span>second-icon</span>');
  });

  it('updates the prepended icon of the selected option of a searchable select', () => {
    const select = render(`<typo3-backend-searchable-select><select id="select">${options}</select></typo3-backend-searchable-select>`);
    select.value = 'second';
    select.dispatchEvent(new Event('change'));

    expect(root.querySelector('.input-group-icon').innerHTML).to.equal('<span>second-icon</span>');
  });
});

describe('FormEngine searchable select layout', () => {
  it('preserves native input-group corners before and after JavaScript enhancement', async () => {
    const frame = fixtureSync<HTMLIFrameElement>(html`<iframe title="Uninitialized selects"></iframe>`);
    await new Promise<void>(resolve => {
      frame.addEventListener('load', () => resolve(), { once: true });
      frame.srcdoc = `<!doctype html><html><head>
        <link rel="stylesheet" href="/typo3/sysext/backend/Resources/Public/Css/backend.css">
      </head><body></body></html>`;
    });
    const view = frame.contentWindow;
    const doc = frame.contentDocument;
    expect(view.customElements.get('typo3-backend-searchable-select')).to.be.undefined;

    const root = fixtureSync<HTMLElement>(html`<div></div>`);
    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = '/typo3/sysext/backend/Resources/Public/Css/backend.css';
    await new Promise<void>(resolve => {
      stylesheet.addEventListener('load', () => resolve(), { once: true });
      root.append(stylesheet);
    });
    const enhanced = document.createElement('div');
    root.append(enhanced);

    const select = '<select class="form-select form-control-adapt"><option>Standard Page</option></select>';
    const icon = '<span class="input-group-text input-group-icon">Icon</span>';
    const button = '<button class="btn btn-default">Action</button>';
    const feedback = '<div class="invalid-feedback">Invalid selection</div>';
    const corners = (element: HTMLElement): string[] => {
      const style = element.ownerDocument.defaultView.getComputedStyle(element);
      expect(style.getPropertyValue('--typo3-input-border-radius').trim()).not.to.equal('');
      return [style.borderTopLeftRadius, style.borderTopRightRadius, style.borderBottomLeftRadius, style.borderBottomRightRadius];
    };

    for (const direction of ['ltr', 'rtl']) {
      doc.documentElement.dir = direction;
      enhanced.dir = direction;
      for (const [name, before, after, validation] of [
        ['standalone', '', '', ''],
        ['prepended icon', icon, '', ''],
        ['appended button', '', button, ''],
        ['icon and button', icon, button, ''],
        ['validation feedback', icon, button + feedback, 'has-validation'],
      ]) {
        doc.body.innerHTML = `
          <div class="input-group ${validation}">${before}${select}${after}</div>
          <div class="input-group ${validation}">${before}<typo3-backend-searchable-select>${select}</typo3-backend-searchable-select>${after}</div>
        `;
        const [reference, fallback] = doc.querySelectorAll<HTMLSelectElement>('select');
        expect(corners(fallback), `${name}, ${direction}, before enhancement`).to.deep.equal(corners(reference));

        enhanced.innerHTML = `<div class="input-group ${validation}">${before}<typo3-backend-searchable-select>${select}</typo3-backend-searchable-select>${after}</div>`;
        const component = enhanced.querySelector('typo3-backend-searchable-select');
        await component.updateComplete;
        await component.updateComplete;
        expect(corners(component.querySelector('.searchable-select-trigger')), `${name}, ${direction}, after enhancement`).to.deep.equal(corners(reference));
      }
    }
  });
});
