import { test, expect, type BrowserContext } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const backend = 'https://preview-backend.example';
const first = 'https://preview-first.example';
const second = 'https://preview-second.example';
const scope = { first: { 0: first + '/' }, second: { 0: second + '/' } };
let tokenId = 0;
const token = (expires: number): string => 'e30.' + Buffer.from(JSON.stringify({ scope, exp: expires, jti: ++tokenId })).toString('base64url') + '.test';

async function mockSites(context: BrowserContext, fallback = false, lifetime = 62): Promise<{ renewals: string[]; documents: string[] }> {
  const renewals: string[] = [];
  const documents: string[] = [];
  const initial = token(Math.floor(Date.now() / 1000) + lifetime);
  await context.addCookies([{ name: 'be_typo_user', value: 'backend-only', url: backend }]);
  await context.route('https://preview-*.example/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.startsWith('/modules/')) {
      const [, , extension, ...parts] = url.pathname.split('/');
      const file = path.resolve(process.cwd(), '..', 'typo3/sysext', extension, 'Resources/Public/JavaScript', ...parts);
      await route.fulfill({ contentType: 'text/javascript', headers: { 'Access-Control-Allow-Origin': '*' }, body: await readFile(file, 'utf8') });
      return;
    }
    if (url.origin === backend && url.pathname === '/renew') {
      expect(url.searchParams.get('token')).toBe('backend-route-token');
      expect(request.headers().cookie).toContain('be_typo_user=backend-only');
      const body = await new Response(request.postData(), { headers: { 'Content-Type': request.headers()['content-type'] } }).formData();
      expect(body.has('token')).toBe(false);
      expect(body.get('previewToken')).toBeTruthy();
      const target = new URL(body.get('url') as string);
      renewals.push(target.href);
      const expires = Math.floor(Date.now() / 1000) + 900;
      target.searchParams.set('_ps', token(expires));
      await route.fulfill({ json: { url: target.href, expires } });
      return;
    }
    if (url.origin === backend) {
      if (url.pathname === '/preview') {
        const target = new URL(url.searchParams.get('previewUrl'));
        target.searchParams.set('_ps', token(Math.floor(Date.now() / 1000) + 900));
        const template = await readFile(path.resolve(process.cwd(), '../typo3/sysext/backend/Resources/Private/Templates/Preview/Show.fluid.html'), 'utf8');
        const html = template
          .replaceAll('{f:translate(key: \'backend.preview:title\')}', 'Preview')
          .replaceAll('{f:translate(key: \'core.core:labels.reload\')}', 'Reload')
          .replaceAll('{f:translate(key: \'backend.preview:live\')}', 'Open live page')
          .replaceAll('{url}', target.href.replaceAll('&', '&amp;'))
          .replaceAll('{liveUrl}', url.searchParams.get('previewUrl').replaceAll('&', '&amp;'));
        await route.fulfill({ contentType: 'text/html', body: `<!doctype html>
          <script>window.TYPO3 = {settings: {previewUrl: '${backend}/preview', ajaxUrls: {preview_renew: '${backend}/renew?token=backend-route-token'}}};</script>
          <script type="importmap">{"imports":{"@typo3/core/":"${backend}/modules/core/","@typo3/backend/":"${backend}/modules/backend/"}}</script>
          ${html}<script type="module" src="${backend}/modules/backend/preview-shell.js"></script>` });
        return;
      }
      if (url.pathname === '/view-module') {
        const template = await readFile(path.resolve(process.cwd(), '../typo3/sysext/viewpage/Resources/Private/Templates/Show.fluid.html'), 'utf8');
        const frameId = template.match(/<iframe[^>]*id="([^"]+)"/)[1];
        await route.fulfill({ contentType: 'text/html', body: `<!doctype html>
          <script>window.TYPO3 = {settings: {previewUrl: '${backend}/preview', ajaxUrls: {preview_renew: '${backend}/renew?token=backend-route-token'}}};</script>
          <script type="importmap">{"imports":{"@typo3/core/":"${backend}/modules/core/","@typo3/backend/":"${backend}/modules/backend/","@typo3/viewpage/":"${backend}/modules/viewpage/","interactjs":"${backend}/modules/core/Contrib/interactjs.js"}}</script>
          <div class="t3js-module-body"><div class="t3js-viewpage-topbar">
            <span class="t3js-preset-custom-label">Custom</span><span class="t3js-viewpage-current-label">Custom</span>
            <a class="t3js-change-orientation" href="#">Rotate</a>
            <input class="t3js-viewpage-input-width" value="800"><input class="t3js-viewpage-input-height" value="600">
            <span class="t3js-preset-custom"><span class="t3js-preset-custom-width">800</span><span class="t3js-preset-custom-height">600</span></span>
          </div><div class="t3js-viewpage-resizeable"><iframe id="${frameId}" src="${first}/?_ps=${initial}"></iframe></div></div>
          <button class="t3js-viewpage-refresh">Reload</button>
          <script type="module" src="${backend}/modules/viewpage/main.js"></script>` });
        return;
      }
      if (url.pathname === '/module-host') {
        await route.fulfill({ contentType: 'text/html', body: `<!doctype html>
          <script>window.TYPO3 = {settings: {previewUrl: '${backend}/preview', ajaxUrls: {preview_renew: '${backend}/renew?token=backend-route-token'}}};</script>
          <script type="importmap">{"imports":{"@typo3/core/":"${backend}/modules/core/","@typo3/backend/":"${backend}/modules/backend/"}}</script>
          <iframe id="module" src="${backend}/module"></iframe>` });
        return;
      }
      const target = first + '/?_ps=' + initial;
      await route.fulfill({ contentType: 'text/html', body: `<!doctype html><title>Preview backend</title>
        <script>window.TYPO3 = {settings: {previewUrl: '${backend}/preview', ajaxUrls: {preview_renew: '${backend}/renew?token=backend-route-token'}}};</script>
        <script type="importmap">{"imports":{"@typo3/core/":"${backend}/modules/core/","@typo3/backend/":"${backend}/modules/backend/"}}</script>
        <iframe id="preview" src="${target}"></iframe><button id="open">Open preview</button>
        <script type="module">
          import PreviewController from '@typo3/backend/preview-controller.js';
          import WindowManager from '@typo3/backend/window-manager.js';
          const frame = document.getElementById('preview');
          new PreviewController(frame.contentWindow, frame.src, frame);
          document.getElementById('open').onclick = () => {
            WindowManager.localOpen('${target}');
          };
        </script>` });
      return;
    }
    expect(request.headers().cookie || '').not.toContain('be_typo_user');
    documents.push(request.url());
    await route.fulfill({ contentType: 'text/html', body: `<!doctype html><title>Frontend</title>
      ${fallback ? '<script>Object.defineProperty(window, "navigation", {value: undefined});</script>' : ''}
      <h1>${url.origin === first ? 'First site' : 'Second site'}</h1>
      <a href="${url.origin === first ? second : first}/next">Other site</a>
      <form action="${second}/search"><input name="q" value="preview"><button>Search</button></form>
      <script type="module" src="${backend}/modules/core/preview-navigation.js"
        data-typo3-preview-navigation data-parent="${backend}" data-scope='${JSON.stringify(scope)}'></script>` });
  });
  return { renewals, documents };
}

test('Renewal updates the token without navigating the idle iframe', async ({ page, context }) => {
  const { renewals, documents } = await mockSites(context);
  await page.clock.install();
  await page.goto(backend);
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(first + '/');
  await page.clock.fastForward(2000);
  await expect.poll(() => renewals.length).toBeGreaterThan(0);
  // Allow asynchronous postMessage and navigation events to finish.
  await page.waitForTimeout(500);
  expect(renewals).toHaveLength(1);
  expect(documents).toHaveLength(1);
  await page.clock.fastForward(60 * 1000);
  await page.waitForTimeout(100);
  expect(renewals).toHaveLength(1);
  expect(documents).toHaveLength(1);
});

test('Backend visibility and pageshow reuse a token until renewal is due', async ({ page, context }) => {
  const { renewals } = await mockSites(context, false, 900);
  await page.clock.install();
  await page.goto(backend);
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(first + '/');
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { value: 'visible' });
    for (let index = 0; index < 3; ++index) {
      document.dispatchEvent(new Event('visibilitychange'));
      window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    }
  });
  await page.waitForTimeout(100);
  expect(renewals).toHaveLength(0);
  await page.clock.fastForward(14 * 60 * 1000);
  await expect.poll(() => renewals.length).toBe(1);
});

test('Same-document history changes synchronize without loading another document', async ({ page, context }) => {
  const { documents } = await mockSites(context, false, 900);
  await page.goto(backend);
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(first + '/');
  const frame = page.frames().find(frame => frame.url().startsWith(first));
  await frame.evaluate(() => {
    const url = new URL(window.location.href);
    url.searchParams.set('section', 'details');
    window.history.replaceState({ section: 'details' }, '', url);
  });
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(first + '/?section=details');
  await page.waitForTimeout(100);
  expect(documents).toHaveLength(1);
  expect(await frame.evaluate(() => window.history.state)).toEqual({ section: 'details' });
});

test('Preview module initializes the frame from the real Core template', async ({ page, context }) => {
  await mockSites(context, true, 900);
  await page.goto(backend + '/view-module');
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(first + '/');
  await page.frameLocator('iframe').getByRole('link', { name: 'Other site' }).click();
  await expect(page.frameLocator('iframe').getByRole('heading')).toHaveText('Second site');
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(second + '/next');
  await page.getByRole('button', { name: 'Reload' }).click();
  await expect(page.frameLocator('iframe').getByRole('heading')).toHaveText('Second site');
});

test('Cross-origin frame navigation survives reload and browser back/forward', async ({ page, context }) => {
  await mockSites(context);
  await page.goto(backend);
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('First site');
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(first + '/');
  await page.frameLocator('#preview').getByRole('link', { name: 'Other site' }).click();
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('Second site');
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(second + '/next');
  await page.reload();
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('Second site');
  await page.goBack();
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('First site');
  await page.goForward();
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('Second site');
});

test('Link and GET form fallback works without Navigation API', async ({ page, context }) => {
  await mockSites(context, true);
  await page.goto(backend);
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('First site');
  await page.frameLocator('#preview').getByRole('button', { name: 'Search' }).click();
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('Second site');
  await expect.poll(() => new URL(page.url()).searchParams.get('previewUrl')).toBe(second + '/search?q=preview');
  await page.frameLocator('#preview').getByRole('link', { name: 'Other site' }).click();
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('First site');
});

test('Messages from an unrelated window cannot navigate the preview', async ({ page, context }) => {
  await mockSites(context);
  await page.goto(backend);
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('First site');
  await page.evaluate(({ second }) => window.postMessage({ type: 'typo3-preview-navigate', url: second }, '*'), { second });
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('First site');
});

test('View button opens a toolbar and iframe with reload and browser history', async ({ page, context }) => {
  const { documents } = await mockSites(context);
  await page.goto(backend);
  const popupPromise = page.waitForEvent('popup');
  await page.getByRole('button', { name: 'Open preview' }).click();
  const popup = await popupPromise;
  await expect.poll(() => new URL(popup.url()).origin).toBe(backend);
  await expect(popup.locator('#typo3-preview-toolbar')).toBeVisible();
  await expect(popup.locator('iframe')).not.toHaveAttribute('sandbox');
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('First site');
  expect(documents).toHaveLength(2);
  await popup.frameLocator('iframe').getByRole('link', { name: 'Other site' }).click();
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('Second site');
  await expect(popup.locator('#typo3-preview-live')).toHaveAttribute('href', second + '/next');
  await popup.reload();
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('Second site');
  await popup.goBack();
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('First site');
  await popup.goForward();
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('Second site');
});

test('Authenticated parent renews the token before expiry', async ({ page, context }) => {
  const { renewals } = await mockSites(context);
  await page.clock.install();
  await page.goto(backend);
  await expect(page.frameLocator('#preview').getByRole('heading')).toHaveText('First site');
  await expect.poll(() => renewals.length).toBeGreaterThan(0);
  const previous = renewals.length;
  await page.clock.fastForward(14 * 60 * 1000);
  await expect.poll(() => renewals.length).toBeGreaterThan(previous);
});

test('New tab remains connected after its backend module is removed', async ({ page, context }) => {
  await mockSites(context);
  await page.goto(backend + '/module-host');
  const popupPromise = page.waitForEvent('popup');
  await page.frameLocator('#module').getByRole('button', { name: 'Open preview' }).click();
  const popup = await popupPromise;
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('First site');
  await page.locator('#module').evaluate(module => module.remove());
  await popup.frameLocator('iframe').getByRole('link', { name: 'Other site' }).click();
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('Second site');
});

test('Preview shell renews and navigates after the opener is closed', async ({ page, context }) => {
  const { renewals } = await mockSites(context, false, 900);
  await page.clock.install();
  await page.goto(backend);
  const popupPromise = page.waitForEvent('popup');
  await page.getByRole('button', { name: 'Open preview' }).click();
  const popup = await popupPromise;
  await expect.poll(() => new URL(popup.url()).searchParams.get('previewUrl')).toBe(first + '/');
  await page.close();
  await popup.clock.fastForward(14 * 60 * 1000);
  await expect.poll(() => renewals.length).toBe(1);
  await popup.frameLocator('iframe').getByRole('link', { name: 'Other site' }).click();
  await expect(popup.frameLocator('iframe').getByRole('heading')).toHaveText('Second site');
  await popup.getByRole('link', { name: 'Open live page' }).click();
  await expect.poll(() => popup.url()).toBe(second + '/next');
  await expect(popup.locator('iframe')).toHaveCount(0);
});
