import { test, expect } from '../../fixtures/setup-fixtures';
import type { BrowserContext, Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

test.describe('Preview in a TYPO3 instance whose backend login reaches the site', () => {
  test('Preview module keeps pages in its frame with a preview token and shows a preview only where there is one', async ({ backend }) => {
    await backend.gotoModule('page_preview');
    // The styleguide generator creates this root page hidden
    await backend.pageTree.open('styleguide frontend demo');

    const frame = backend.contentFrame.locator('iframe[title="View selected page in different screen resolutions"]');
    await expect(frame).toHaveAttribute('src', /[?&]__t3ct=/);
    await expect(frame).not.toHaveAttribute('credentialless');
    const site = frame.contentFrame();
    await expect(site.getByRole('heading', { name: 'styleguide frontend demo' })).toBeVisible();
    await expect(site.locator('#typo3-preview-info')).toHaveCount(1);

    await site.getByRole('link', { name: 'text', exact: true }).click();

    await expect(site.getByRole('heading', { name: 'text', exact: true }).first()).toBeVisible();
    await expect.poll(async () => site.locator('body').evaluate(() => window.location.href)).toMatch(/\/text\?__t3ct=/);
    await expect(site.locator('#typo3-preview-info')).toHaveCount(0);
  });

  test('View opens the page directly where the backend login reaches the site', async ({ backend, page }) => {
    await backend.gotoModule('page_preview');
    await backend.pageTree.open('styleguide frontend demo');
    const popupPromise = page.waitForEvent('popup');
    await backend.contentFrame.locator('.module-docheader').getByRole('button', { name: 'View webpage' }).click();
    const popup = await popupPromise;

    await expect(popup).not.toHaveURL(/\/typo3\/preview\?/);
    await expect(popup).not.toHaveURL(/[?&]__t3ct=/);
    await expect(popup.getByRole('heading', { name: 'styleguide frontend demo' })).toBeVisible();
  });
});

const backendOrigin = 'https://preview-backend.example';
const first = 'https://preview-first.example';
const second = 'https://preview-second.example';
const hosts = ['preview-first.example', 'preview-second.example'];

type Site = { renewals: URL[], previousTokens: string[] };

/**
 * Serves a backend preview page and two sites from fixed origins, with the built
 * JavaScript modules of the checkout, so the browser side runs without a TYPO3 instance.
 */
async function serve(context: BrowserContext, options: { expiresIn?: number, expiredPage?: boolean } = {}): Promise<Site> {
  const site: Site = { renewals: [], previousTokens: [] };
  let issued = 0;
  const sign = (url: URL): string => {
    const signed = new URL(url);
    signed.searchParams.set('__t3ct', 'token-' + (++issued));
    return signed.href;
  };
  await context.route(/^https:\/\/preview-(backend|first|second|outside)\.example\//, async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.startsWith('/modules/')) {
      const [, , extension, ...parts] = url.pathname.split('/');
      const file = path.resolve(process.cwd(), '..', 'typo3/sysext', extension, 'Resources/Public/JavaScript', ...parts);
      await route.fulfill({ contentType: 'text/javascript', headers: { 'Access-Control-Allow-Origin': '*' }, body: await readFile(file, 'utf8') });
      return;
    }
    if (url.origin === backendOrigin && url.pathname === '/renew') {
      const body = await new Response(request.postDataBuffer(), { headers: { 'Content-Type': request.headers()['content-type'] } }).formData();
      const target = new URL(body.get('url') as string);
      site.renewals.push(target);
      site.previousTokens.push(body.get('previewToken') as string);
      await route.fulfill({ json: { url: sign(target), expiresIn: 900 } });
      return;
    }
    if (url.origin === backendOrigin) {
      const start = options.expiredPage ? first + '/expired' : first + '/one';
      const settings = { url: sign(new URL(start)), renewUrl: backendOrigin + '/renew', page: 1, hosts, expiresIn: options.expiresIn ?? 900 };
      await route.fulfill({
        contentType: 'text/html', body: `<!doctype html>
          <script type="importmap">{"imports":{"@typo3/core/":"${backendOrigin}/modules/core/","@typo3/backend/":"${backendOrigin}/modules/backend/"}}</script>
          <div data-preview-settings='${JSON.stringify(settings)}'>
            <div data-preview-notice hidden data-notice-failed="Preview failed"></div>
            <iframe data-preview-frame></iframe>
          </div>
          <script type="module" src="${backendOrigin}/modules/backend/preview/preview-page.js"></script>`,
      });
      return;
    }
    if (url.pathname === '/expired') {
      await route.fulfill({
        status: 403, contentType: 'text/html', body: `<!doctype html><p>expired</p>
          <script>window.parent.postMessage({ type: 'typo3:preview:expired', url: location.href, reason: 'expired' }, '${backendOrigin}');</script>`,
      });
      return;
    }
    await route.fulfill({
      contentType: 'text/html', body: `<!doctype html><h1>${url.host}${url.pathname}</h1>
        <a href="${url.origin}/two?__t3ct=${url.searchParams.get('__t3ct')}">Two</a>
        <form id="login" method="post" action="${url.origin}/posted?__t3ct=${url.searchParams.get('__t3ct')}"><button>Log in</button></form>
        <form id="search" method="get" action="${url.origin}/searched"><input type="hidden" name="__t3ct" value="${url.searchParams.get('__t3ct')}"><button>Search</button></form>
        <script type="module" src="${url.origin}/modules/frontend/preview-navigation.js"
          data-preview-backend="${backendOrigin}" data-preview-hosts='${JSON.stringify(hosts)}' data-preview-page="${url.pathname === '/two' ? 2 : 1}"></script>`,
    });
  });
  return site;
}

function frameUrl(page: Page): URL {
  return new URL(page.frames().find(frame => frame.url().startsWith('https://preview-') && !frame.url().startsWith(backendOrigin)).url());
}

test.describe('Preview page around the frame', () => {
  test('mirrors the address of the frame without the token', async ({ browser }) => {
    const context = await browser.newContext();
    await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');

    await expect(page.locator('iframe').contentFrame().getByRole('heading')).toHaveText('preview-first.example/one');
    await expect.poll(() => new URL(page.url()).searchParams.get('url')).toBe(first + '/one');
    await context.close();
  });

  test('renews the token before it expires without reloading the frame', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context, { expiresIn: 61 });
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');
    await frame.locator('body').evaluate(() => Object.assign(window, { notReloaded: true }));

    await expect.poll(() => site.renewals.length, { timeout: 5000 }).toBe(1);
    expect(site.previousTokens).toEqual(['token-1']);
    await expect.poll(() => frameUrl(page).searchParams.get('__t3ct')).toBe('token-2');
    await expect(frame.getByRole('link', { name: 'Two' })).toHaveAttribute('href', /__t3ct=token-2$/);
    await expect(frame.locator('#login')).toHaveAttribute('action', /__t3ct=token-2$/);
    await expect(frame.locator('#search input[name="__t3ct"]')).toHaveValue('token-2');
    expect(await frame.locator('body').evaluate(() => (window as unknown as { notReloaded?: boolean }).notReloaded)).toBe(true);
    await context.close();
  });

  test('keeps the token in navigations started by scripts', async ({ browser }) => {
    const context = await browser.newContext();
    await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    await frame.locator('body').evaluate(() => window.location.assign('/two?x=1'));

    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/two');
    expect(frameUrl(page).searchParams.get('__t3ct')).toBe('token-1');
    expect(frameUrl(page).searchParams.get('x')).toBe('1');
    await expect.poll(() => new URL(page.url()).searchParams.get('page')).toBe('2');
    await context.close();
  });

  test('keeps the query of navigations started by scripts as it is', async ({ browser }) => {
    const context = await browser.newContext();
    await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    await frame.locator('body').evaluate(() => window.location.assign('/two?flag&a[]=1&q=a+b&sig=ab%2bcd'));

    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/two');
    expect(frameUrl(page).search).toBe('?flag&a[]=1&q=a+b&sig=ab%2bcd&__t3ct=token-1');
    await expect.poll(() => new URL(page.url()).searchParams.get('url')).toBe(first + '/two?flag&a[]=1&q=a+b&sig=ab%2bcd');
    await context.close();
  });

  test('loads another host of the preview with a fresh token', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    await frame.locator('body').evaluate((element, target) => window.location.assign(target), second + '/one');

    await expect(frame.getByRole('heading')).toHaveText('preview-second.example/one');
    expect(site.renewals.map(url => url.href)).toEqual([second + '/one']);
    expect(site.previousTokens).toEqual(['token-1']);
    expect(frameUrl(page).searchParams.get('__t3ct')).toBe('token-2');
    await context.close();
  });

  test('opens other hosts in a new tab without a token', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    const popupPromise = context.waitForEvent('page');
    await frame.locator('body').evaluate(() => window.location.assign('https://preview-outside.example/away?__t3ct=token-1'));
    const popup = await popupPromise;

    await expect.poll(() => popup.url()).toBe('https://preview-outside.example/away');
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');
    expect(site.renewals).toEqual([]);
    await context.close();
  });

  test('ignores messages that do not come from the frame', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');
    await expect(page.locator('iframe').contentFrame().getByRole('heading')).toHaveText('preview-first.example/one');

    await page.evaluate(target => window.postMessage({ type: 'typo3:preview:navigate', url: target }, '*'), second + '/one');
    await page.waitForTimeout(500);

    expect(site.renewals).toEqual([]);
    await expect(page.locator('iframe').contentFrame().getByRole('heading')).toHaveText('preview-first.example/one');
    await context.close();
  });

  test('reloads an expired preview once and then stops', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context, { expiredPage: true });
    const page = await context.newPage();
    await page.goto(backendOrigin + '/preview?page=1');

    await expect(page.locator('[data-preview-notice]')).toBeVisible();
    await expect(page.locator('[data-preview-notice]')).toHaveText('Preview failed');
    expect(site.renewals.map(url => url.href)).toEqual([first + '/expired']);
    await context.close();
  });
});
