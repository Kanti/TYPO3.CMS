import { test, expect } from '../../fixtures/setup-fixtures';

test.describe('Styleguide searchable select', () => {
  test.beforeEach(async ({ page, backend, workspace }) => {
    await page.goto('module/web/layout');
    await workspace.ensureLiveWorkspace();
    await backend.gotoModule('styleguide');

    const contentFrame = backend.contentFrame;
    await contentFrame.locator('a[aria-label="Open Component Library: Components module"]').click();
    await contentFrame.getByRole('link', { name: 'Open Select component' }).click();
  });

  test('filters grouped options while typing and picks the first match on Enter', async ({ page, backend }) => {
    const contentFrame = backend.contentFrame;
    const searchable = contentFrame.locator('typo3-backend-searchable-select').filter({
      has: contentFrame.locator('#selectFormSearchableDefault'),
    });
    const trigger = searchable.locator('.searchable-select-trigger');
    await expect(trigger).toHaveAccessibleName('Form label: Apple');
    await contentFrame.locator('label[for="selectFormSearchableDefault"]').click();
    await expect(trigger).toBeFocused();
    await trigger.click();
    const listbox = searchable.getByRole('listbox', { name: 'Form label' });
    const selected = listbox.getByRole('option', { selected: true });
    await expect(selected.locator('.searchable-select-option-indicator')).toHaveAttribute('aria-hidden', 'true');
    await expect(selected.locator('typo3-backend-icon[identifier="actions-check"][size="small"]')).toBeVisible();
    const search = searchable.getByRole('combobox', { name: 'Filter by item' });
    await search.pressSequentially('cafe');
    await expect(listbox.getByRole('option')).toHaveText(['Café au lait']);
    await expect(listbox.getByRole('group')).toHaveCount(1);
    await expect(listbox.getByRole('group', { name: 'Beverages' })).toBeVisible();

    await search.press('Enter');
    await expect(trigger).toHaveText('Café au lait');
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#alert-container typo3-notification-message')).toContainText('Option picked');
  });

  test('returns from dropdown results to search with ArrowUp and continues typing', async ({ page, backend }) => {
    const searchable = backend.contentFrame.locator('typo3-backend-searchable-select').filter({
      has: backend.contentFrame.getByRole('button', { name: 'Show', exact: true }),
    });
    await searchable.locator('.dropdown-toggle').click();
    const search = searchable.getByRole('searchbox', { name: 'Filter by item' });
    await search.pressSequentially('cafe');
    await search.press('ArrowDown');
    await expect(searchable.getByRole('link', { name: 'Café au lait' })).toBeFocused();

    await page.keyboard.press('ArrowUp');
    await expect(search).toBeFocused();
    await expect(search).toHaveValue('cafe');
    await page.keyboard.type(' au');
    await expect(search).toHaveValue('cafe au');
    await expect(searchable.locator('.dropdown-menu')).toBeVisible();
    await expect(searchable.locator('.dropdown-item:visible')).toHaveText(['Café au lait']);
  });

  test('reveals search for a short select, keeps focus between keypresses and clears only the query', async ({ page, backend }) => {
    const searchable = backend.contentFrame.locator('typo3-backend-searchable-select').filter({
      has: backend.contentFrame.locator('#shortSearchableSelect'),
    });
    const trigger = searchable.locator('.searchable-select-trigger');
    const search = searchable.getByRole('combobox', { name: 'Filter by item' });
    await trigger.click();
    await expect(search).toBeHidden();

    await page.keyboard.press('f');
    await expect(search).toHaveValue('f');
    await expect(search).toBeFocused();
    await page.keyboard.press('r');
    await expect(search).toHaveValue('fr');
    await expect(search).toBeFocused();
    await expect(searchable.getByRole('option')).toHaveText(['Français']);

    await searchable.getByRole('button', { name: 'Clear input' }).click();
    await expect(search).toHaveValue('');
    await expect(search).toBeFocused();
    await expect(searchable.getByRole('option')).toHaveCount(3);
    await expect(trigger).toHaveText('Deutsch');
    await expect(searchable.locator('select')).toHaveValue('Deutsch');
  });

  test.describe('with primary touch input', () => {
    test.use({ hasTouch: true });

    test('keeps native select handling after reconnecting and still filters dropdown menus', async ({ page, backend }) => {
      const contentFrame = backend.contentFrame;
      const searchable = contentFrame.locator('typo3-backend-searchable-select').filter({
        has: contentFrame.locator('#selectFormSearchableDefault'),
      });
      const select = searchable.locator('select');
      expect(await select.evaluate(() => window.matchMedia('(pointer: coarse) and (hover: none)').matches)).toBe(true);
      await expect(select).toBeVisible();
      await expect(searchable.locator('.searchable-select-trigger, .searchable-select-popup')).toHaveCount(0);
      const attributes = await select.evaluate(element => Array.from(element.attributes, attribute => [attribute.name, attribute.value]));
      await searchable.evaluate(element => {
        const parent = element.parentNode;
        const next = element.nextSibling;
        element.remove();
        parent.insertBefore(element, next);
      });
      await expect(select).toBeVisible();
      expect(await select.evaluate(element => Array.from(element.attributes, attribute => [attribute.name, attribute.value]))).toEqual(attributes);
      await expect(searchable.locator('.searchable-select-trigger, .searchable-select-popup')).toHaveCount(0);
      await select.focus();
      await expect(select).toBeFocused();
      await select.selectOption({ label: 'Café au lait' });
      await expect(select.locator('option:checked')).toHaveText('Café au lait');
      await expect(page.locator('#alert-container typo3-notification-message')).toContainText('Option picked');

      const dropdown = contentFrame.locator('typo3-backend-searchable-select').filter({
        has: contentFrame.getByRole('button', { name: 'Show', exact: true }),
      });
      await dropdown.locator('.dropdown-toggle').tap();
      const search = dropdown.getByRole('searchbox', { name: 'Filter by item' });
      await search.fill('cafe');
      await expect(dropdown.locator('.dropdown-item:visible')).toHaveText(['Café au lait']);
    });
  });
});
