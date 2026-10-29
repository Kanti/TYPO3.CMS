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

type ServeOptions = {
  expiresIn?: number,
  // The address the backend page loads into its frame first
  start?: string,
  // The origin of the backend, which may also be the origin of a site
  backend?: string,
};

/**
 * Serves a backend preview page below /typo3/ and two sites from fixed origins, with
 * the built JavaScript modules of the checkout, so the browser side runs without a
 * TYPO3 instance. Every page of a site shows its host, its path and how it was sent.
 */
async function serve(context: BrowserContext, options: ServeOptions = {}): Promise<Site> {
  const backend = options.backend ?? backendOrigin;
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
    if (url.origin === backend && url.pathname === '/typo3/renew') {
      const body = await new Response(request.postDataBuffer(), { headers: { 'Content-Type': request.headers()['content-type'] } }).formData();
      const target = new URL(body.get('url') as string);
      site.renewals.push(target);
      site.previousTokens.push(body.get('previewToken') as string);
      await route.fulfill({ json: { url: sign(target), expiresIn: 900 } });
      return;
    }
    if (url.origin === backend && url.pathname === '/typo3/preview') {
      const settings = { url: sign(new URL(options.start ?? first + '/one')), renewUrl: backend + '/typo3/renew', page: 1, hosts, expiresIn: options.expiresIn ?? 900 };
      await route.fulfill({
        contentType: 'text/html', body: `<!doctype html>
          <script type="importmap">{"imports":{"@typo3/core/":"${backend}/modules/core/","@typo3/backend/":"${backend}/modules/backend/"}}</script>
          <div data-preview-settings='${JSON.stringify(settings)}'>
            <div data-preview-notice hidden data-notice-failed="Preview failed"></div>
            <iframe data-preview-frame></iframe>
          </div>
          <script type="module" src="${backend}/modules/backend/preview/preview-page.js"></script>`,
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
    if (request.method() === 'POST') {
      await route.fulfill({ contentType: 'text/html', body: `<!doctype html><h1>POST ${url.host}${url.pathname} ${request.postData()}</h1>` });
      return;
    }
    const token = url.searchParams.get('__t3ct');
    // Links the script cannot read as an address: an SVG link and a link it cannot parse
    const unreadableLinks = url.pathname === '/unreadable-links'
      ? `<svg><a href="${url.origin}/drawing?__t3ct=${token}"><text>Drawing</text></a></svg><a href="http://[broken">Broken</a>`
      : '';
    await route.fulfill({
      contentType: 'text/html', body: `<!doctype html><h1>${url.host}${url.pathname}</h1>
        ${unreadableLinks}
        <a href="${url.origin}/two?__t3ct=${token}">Two</a>
        <a href="${url.origin}/two?__t3ct=${token}" target="_top">Two in the window</a>
        <form id="login" method="post" action="${url.origin}/posted?__t3ct=${token}"><button>Log in</button></form>
        <form id="search" method="get" action="${url.origin}/searched"><input type="hidden" name="__t3ct" value="${token}"><button>Search</button></form>
        <form id="elsewhere" method="post" action="${second}/posted?__t3ct=${token}"><input type="hidden" name="q" value="x"><button>Order elsewhere</button></form>
        <form id="subscribe" method="post" action="https://preview-outside.example/subscribe"><input type="hidden" name="email" value="editor"><button>Subscribe</button></form>
        <script type="module" src="${url.origin}/modules/frontend/preview-navigation.js"
          data-preview-backend="${backend}" data-preview-hosts='${JSON.stringify(hosts)}' data-preview-page="${url.pathname === '/two' ? 2 : 1}"></script>`,
    });
  });
  return site;
}

function frameUrl(page: Page): URL {
  return new URL(page.frames().find(frame => frame !== page.mainFrame()).url());
}

test.describe('Preview page around the frame', () => {
  test('mirrors the address of the frame without the token', async ({ browser }) => {
    const context = await browser.newContext();
    await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');

    await expect(page.locator('iframe').contentFrame().getByRole('heading')).toHaveText('preview-first.example/one');
    await expect.poll(() => new URL(page.url()).searchParams.get('url')).toBe(first + '/one');
    await context.close();
  });

  test('renews the token before it expires without reloading the frame', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context, { expiresIn: 61 });
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');
    await frame.locator('body').evaluate(() => Object.assign(window, { notReloaded: true }));

    await expect.poll(() => site.renewals.length, { timeout: 5000 }).toBe(1);
    expect(site.previousTokens).toEqual(['token-1']);
    await expect.poll(() => frameUrl(page).searchParams.get('__t3ct')).toBe('token-2');
    await expect(frame.getByRole('link', { name: 'Two', exact: true })).toHaveAttribute('href', /__t3ct=token-2$/);
    await expect(frame.locator('#login')).toHaveAttribute('action', /__t3ct=token-2$/);
    await expect(frame.locator('#search input[name="__t3ct"]')).toHaveValue('token-2');
    expect(await frame.locator('body').evaluate(() => (window as unknown as { notReloaded?: boolean }).notReloaded)).toBe(true);
    await context.close();
  });

  test('keeps the token in navigations started by scripts', async ({ browser }) => {
    const context = await browser.newContext();
    await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');
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
    await page.goto(backendOrigin + '/typo3/preview?page=1');
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
    await page.goto(backendOrigin + '/typo3/preview?page=1');
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
    await page.goto(backendOrigin + '/typo3/preview?page=1');
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
    await page.goto(backendOrigin + '/typo3/preview?page=1');
    await expect(page.locator('iframe').contentFrame().getByRole('heading')).toHaveText('preview-first.example/one');

    await page.evaluate(target => window.postMessage({ type: 'typo3:preview:navigate', url: target }, '*'), second + '/one');
    await page.waitForTimeout(500);

    expect(site.renewals).toEqual([]);
    await expect(page.locator('iframe').contentFrame().getByRole('heading')).toHaveText('preview-first.example/one');
    await context.close();
  });

  /**
   * A form sent via HTTP POST to another host of the preview carries the token in its
   * action, as forms rendered with f:form do. It is sent as it is, with its data,
   * instead of being loaded by the page around the frame without them.
   */
  test('sends a form via HTTP POST to another host of the preview with its data', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    await frame.getByRole('button', { name: 'Order elsewhere' }).click();

    await expect(frame.getByRole('heading')).toHaveText('POST preview-second.example/posted q=x');
    expect(site.renewals).toEqual([]);
    await context.close();
  });

  /**
   * A form sent via HTTP POST to a service on another host, such as a newsletter
   * provider, is sent in the frame as it would be without the preview: with its data,
   * and without a token.
   */
  test('sends a form via HTTP POST to another site in the frame with its data', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    await frame.getByRole('button', { name: 'Subscribe' }).click();

    await expect(frame.getByRole('heading')).toHaveText('POST preview-outside.example/subscribe email=editor');
    expect(context.pages()).toHaveLength(1);
    expect(site.renewals).toEqual([]);
    await context.close();
  });

  /**
   * A page may hold links the script cannot read as an address, such as a link in an
   * inline SVG or one whose address cannot be parsed. They must not keep the renewed
   * token from the links and forms after them.
   */
  test('renews the token in links and forms of a page that holds links the script cannot read', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context, { expiresIn: 61, start: first + '/unreadable-links' });
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/unreadable-links');

    await expect.poll(() => site.renewals.length, { timeout: 5000 }).toBe(1);
    await expect(frame.getByRole('link', { name: 'Two', exact: true })).toHaveAttribute('href', /__t3ct=token-2$/);
    await expect(frame.locator('#login')).toHaveAttribute('action', /__t3ct=token-2$/);
    await expect(frame.locator('#search input[name="__t3ct"]')).toHaveValue('token-2');
    await expect(frame.getByRole('link', { name: 'Broken' })).toHaveAttribute('href', 'http://[broken');
    await context.close();
  });

  /**
   * A link of the site may target the window around the frame, as target="_top" does.
   * In the preview it would replace the backend with the page; it stays in the frame,
   * as forms rendered with f:form do.
   */
  test('keeps a link that targets the window around the frame in the frame', async ({ browser }) => {
    const context = await browser.newContext();
    await serve(context);
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    await frame.getByRole('link', { name: 'Two in the window' }).click();

    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/two');
    expect(new URL(page.url()).origin).toBe(backendOrigin);
    expect(frameUrl(page).searchParams.get('__t3ct')).toBe('token-1');
    await context.close();
  });

  test('reloads an expired preview once and then stops', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context, { start: first + '/expired' });
    const page = await context.newPage();
    await page.goto(backendOrigin + '/typo3/preview?page=1');

    await expect(page.locator('[data-preview-notice]')).toBeVisible();
    await expect(page.locator('[data-preview-notice]')).toHaveText('Preview failed');
    expect(site.renewals.map(url => url.href)).toEqual([first + '/expired']);
    await context.close();
  });
});

/**
 * A backend on the origin of a site, as in an installation with one domain, shares the
 * origin with the page in its frame. Browsers then tell the page about navigations the
 * page around the frame starts, which the page must let pass with their fresh token.
 */
test.describe('Preview page of a backend on the origin of a site', () => {
  test('loads another host of the preview once with a fresh token', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context, { backend: first });
    const page = await context.newPage();
    await page.goto(first + '/typo3/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    await frame.locator('body').evaluate((element, target) => window.location.assign(target), second + '/one');

    await expect(frame.getByRole('heading')).toHaveText('preview-second.example/one');
    await page.waitForTimeout(500);
    expect(site.renewals.map(url => url.href)).toEqual([second + '/one']);
    expect(frameUrl(page).searchParams.get('__t3ct')).toBe('token-2');
    await context.close();
  });

  test('loads the page again with the fresh token of the backend', async ({ browser }) => {
    const context = await browser.newContext();
    const site = await serve(context, { backend: first });
    const page = await context.newPage();
    await page.goto(first + '/typo3/preview?page=1');
    const frame = page.locator('iframe').contentFrame();
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');

    // The site refuses a token at the address, the page around the frame loads it again with a fresh one
    await frame.locator('body').evaluate((element, backend) => window.parent.postMessage({ type: 'typo3:preview:expired', url: window.location.href, reason: 'expired' }, backend), first);

    await expect.poll(() => frameUrl(page).searchParams.get('__t3ct')).toBe('token-2');
    await expect(frame.getByRole('heading')).toHaveText('preview-first.example/one');
    expect(site.renewals.map(url => url.href)).toEqual([first + '/one']);
    await context.close();
  });
});
