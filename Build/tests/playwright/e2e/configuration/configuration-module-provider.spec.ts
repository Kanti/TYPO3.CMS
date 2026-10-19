import { test, expect } from '../../fixtures/setup-fixtures';

test.describe('Configuration Module Provider', () => {
  test.beforeEach(async ({ backend }) => {
    await backend.gotoModule('system_config');
  });

  test('Select and display configuration', async ({ backend }) => {
    const contentFrame = backend.contentFrame;

    // Explicitly select the default provider (TYPO3_CONF_VARS) to ensure clean state
    await backend.docHeader.selectInDropDown('Configuration to show', 'TYPO3_CONF_VARS (Global Configuration)');

    // Module can be accessed
    // Sorting is applied and TYPO3_CONF_VARS is the default provider to display
    await expect(contentFrame.locator('h1')).toContainText('Configuration of "TYPO3_CONF_VARS (Global Configuration)"');
    await expect(contentFrame.locator('#ConfigurationView')).toBeVisible();

    // Middleware provider can be loaded
    await backend.docHeader.selectInDropDown('Configuration to show', 'HTTP Middlewares (PSR-15)');
    await expect(contentFrame.locator('h1')).toContainText('Configuration of "HTTP Middlewares (PSR-15)"');

    // Tree search can be applied
    await contentFrame.locator('#searchValue').fill('authentication');
    await expect(contentFrame.getByText('typo3/cms-frontend/authentication').first()).toBeVisible();
  });

  test('filters providers in the original dropdown', async ({ backend }) => {
    const header = backend.contentFrame.locator('.t3js-module-docheader-buttons');
    await header.getByRole('button', { name: 'Configuration to show' }).click();
    const menu = header.locator('.dropdown-menu:popover-open');
    const search = menu.getByRole('searchbox', { name: 'Filter by item' });
    await search.fill('psr-15');
    await expect(menu.locator('li:not([hidden]) > .dropdown-item')).toHaveText(['HTTP Middlewares (PSR-15)']);
    await search.press('Enter');
    await expect(backend.contentFrame.locator('h1')).toContainText('Configuration of "HTTP Middlewares (PSR-15)"');
  });

  test('See all pages in dropdown', async ({ backend }) => {
    const contentFrame = backend.contentFrame;

    const itemList = [
      'TYPO3_CONF_VARS (Global Configuration)',
      'TCA (Table Configuration Array)',
      'T3_SERVICES (Registered Services)',
      'Table permissions per page type',
      'User Settings (BE_USER->uc)',
      'User TSconfig (BE_USER->getTSConfig())',
      'Backend Routes',
      'Backend Modules',
      'HTTP Middlewares (PSR-15)',
      'Sites: TCA Configuration',
      'Sites: YAML Configuration',
      'Event Listeners (PSR-14)',
      'MFA Providers',
    ];

    for (const item of itemList) {
      await backend.docHeader.selectInDropDown('Configuration to show', item);

      await expect(contentFrame.locator('h1')).toContainText(`Configuration of "${item}"`);
    }
  });
});
