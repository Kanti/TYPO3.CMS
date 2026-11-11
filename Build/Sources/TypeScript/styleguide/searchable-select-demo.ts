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

import Notification from '@typo3/backend/notification';
import RegularEvent from '@typo3/core/event/regular-event';
import labels from '~labels/styleguide.messages';

/**
 * Shows the option picked in a searchable select, where a
 * navigation would load another page.
 */
class SearchableSelectDemo {
  constructor() {
    new RegularEvent('change', (e: Event, select: HTMLSelectElement): void => {
      const option = select.selectedOptions[0];
      Notification.info(
        labels.get('component.select.searchable.notification.title'),
        labels.get('component.select.searchable.notification.message', { label: option?.label ?? '', value: select.value }),
      );
    }).delegateTo(document, 'typo3-backend-searchable-select > select');

    new RegularEvent('click', (e: Event, item: HTMLAnchorElement): void => {
      e.preventDefault();
      item.closest('.dropdown-menu').querySelector('[aria-selected="true"]')?.removeAttribute('aria-selected');
      item.setAttribute('aria-selected', 'true');
      Notification.info(
        labels.get('component.select.searchable.notification.title'),
        labels.get('component.select.searchable.notification.message', { label: item.title, value: item.getAttribute('href') }),
      );
    }).delegateTo(document, 'typo3-backend-searchable-select .dropdown-item');
  }
}

export default new SearchableSelectDemo();
