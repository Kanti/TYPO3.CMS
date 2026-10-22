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

import { expect, fixture, html } from '@open-wc/testing';
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
      const root = await fixture<HTMLElement>(html`
        <form class="t3js-formengine-field-item">
          <div class="form-control-wrap">
            <label for="formengine-select">Language</label>
            <div class="input-group">
              <span class="input-group-icon">de-icon</span>
              <select id="formengine-select" name="language" class="form-select">
                <option value="en">English</option>
                <option value="de" selected>Deutsch</option>
                <option value="fr" data-icon="&lt;span&gt;fr-icon&lt;/span&gt;">Français</option>
              </select>
            </div>
          </div>
        </form>
      `);
      const onChange = sinon.stub(FormEngine, 'processOnFieldChange');
      initializer.initialize('#formengine-select', { onChange: [] });
      await new Promise<void>(resolve => queueMicrotask(resolve));
      const component = root.querySelector('typo3-backend-select');
      const trigger = component.querySelector('button');
      trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'f', bubbles: true, cancelable: true }));
      expect(component.querySelector('.text-highlight').textContent).to.equal('F');
      component.querySelector<HTMLElement>('[role="option"]').click();
      expect(root.querySelector<HTMLSelectElement>('select').value).to.equal('fr');
      expect(new FormData(root as HTMLFormElement).get('language')).to.equal('fr');
      expect(root.querySelector('.input-group-icon').textContent).to.equal('fr-icon');
      expect(onChange.calledOnce).to.be.true;
    });
  });
}
