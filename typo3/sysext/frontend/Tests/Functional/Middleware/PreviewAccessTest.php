<?php

declare(strict_types=1);

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

namespace TYPO3\CMS\Frontend\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Clock\SystemClock;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Frontend\Middleware\PreviewAccess;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreviewAccessTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
    private const BACKEND = 'https://backend.example';

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8'],
    ];

    protected array $coreExtensionsToLoad = ['workspaces', 'redirects', 'adminpanel'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => '4408d27a916d51e624b69af3554f516dbab61037a9f7b9fd6f81b4d3bedeccb6',
            'caching' => [
                'cacheConfigurations' => [
                    'pages' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $pages->update('pages', ['slug' => '/'], ['uid' => 1]);
        $pages->update('pages', ['slug' => '/hidden', 'hidden' => 1], ['uid' => 2]);
        $pages->update('pages', ['slug' => '/hidden/protected', 'fe_group' => '5'], ['uid' => 3]);
        $pages->update('pages', ['slug' => '/shortcut', 'doktype' => 4, 'shortcut' => 2, 'shortcut_mode' => 0], ['uid' => 4]);
        $pages->update('pages', ['slug' => '/external', 'doktype' => 3, 'link' => 'https://outside.example/'], ['uid' => 5]);
        $pages->update('pages', ['slug' => '/scheduled'], ['uid' => 6]);
        $pages->update('pages', ['slug' => '/'], ['uid' => 7]);
        $pages->insert('pages', ['uid' => 8, 'pid' => 1, 'title' => 'Root German', 'slug' => '/', 'sys_language_uid' => 1, 'l10n_parent' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_groups')
            ->insert('fe_groups', ['uid' => 5, 'pid' => 1, 'title' => 'Preview group']);
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 1, 'pid' => 1, 'header' => 'Visible content']);
        $content->insert('tt_content', ['uid' => 2, 'pid' => 1, 'header' => 'Hidden content', 'hidden' => 1]);
        $setup = 'page = PAGE' . LF
            . 'page.10 = TEXT' . LF . 'page.10.data = page:title' . LF
            . 'page.20 = TEXT' . LF . 'page.20.value = Second site' . LF . 'page.20.typolink.parameter = 7' . LF
            . 'page.30 = TEXT' . LF . 'page.30.value = Hidden' . LF . 'page.30.typolink.parameter = 2' . LF
            . 'page.40 = TEXT' . LF . 'page.40.value = Outside' . LF . 'page.40.typolink.parameter = https://outside.example/' . LF
            . 'page.50 = CONTENT' . LF . 'page.50.table = tt_content' . LF . 'page.50.select.pidInList = 1' . LF
            . 'page.50.renderObj = TEXT' . LF . 'page.50.renderObj.field = header' . LF
            . 'config.admPanel = 1' . LF
            . '[backend.user.userId == 1]' . LF . 'page.60 = TEXT' . LF . 'page.60.value = Backend user 1' . LF . '[END]' . LF
            . '[backend.user.userId == 2]' . LF . 'page.60 = TEXT' . LF . 'page.60.value = Backend user 2' . LF . '[END]' . LF;
        $backendUsers = $this->get(ConnectionPool::class)->getConnectionForTable('be_users');
        $backendUsers->insert('be_users', ['uid' => 1, 'pid' => 0, 'username' => 'admin', 'admin' => 1]);
        $backendUsers->insert('be_users', ['uid' => 2, 'pid' => 0, 'username' => 'editor', 'admin' => 0, 'usergroup' => '1', 'options' => 3, 'workspace_perms' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1', 'allowed_languages' => '0', 'workspace_perms' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => 1, 'pid' => 0, 'title' => 'Draft']);
        $this->setUpFrontendRootPage(1, [], ['config' => $setup]);
        $this->setUpFrontendRootPage(7, [], ['config' => $setup]);
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
            array_replace($this->buildLanguageConfiguration('DE', '/de/'), ['enabled' => false]),
        ]);
        $this->writeSiteConfiguration(
            'second',
            $this->buildSiteConfiguration(7, 'http://second.example/'),
            [$this->buildDefaultLanguageConfiguration('EN', '/')],
            csp: ['enforce' => true]
        );
    }

    #[Test]
    public function validTokenShowsHiddenPageWithoutCookieOrCache(): void
    {
        $response = $this->preview('https://first.example/hidden', $this->sign());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-2', (string)$response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('frame-ancestors ' . self::BACKEND, $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('ETag'));
        self::assertStringContainsString('preview-navigation.js', (string)$response->getBody());
        self::assertStringContainsString('data-preview-backend="' . self::BACKEND . '"', (string)$response->getBody());
        self::assertStringContainsString('typo3-preview-info', (string)$response->getBody());

        $live = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/hidden'));
        self::assertSame(404, $live->getStatusCode());
    }

    #[Test]
    public function hiddenContentIsShownOnlyIfTheTokenAllowsIt(): void
    {
        $withHiddenContent = new PreviewProfile(0, false, true, 0, []);
        self::assertStringContainsString('Hidden content', (string)$this->preview('https://first.example/', $this->sign($withHiddenContent))->getBody());

        $withoutHiddenContent = new PreviewProfile(0, true, false, 0, []);
        $response = $this->preview('https://first.example/', $this->sign($withoutHiddenContent));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Visible content', (string)$response->getBody());
        self::assertStringNotContainsString('Hidden content', (string)$response->getBody());
    }

    #[Test]
    public function siteContentSecurityPolicyAllowsOnlyTheBackendToFrame(): void
    {
        $response = $this->preview('http://second.example/', $this->sign(), null);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('frame-ancestors ' . self::BACKEND, $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString("frame-ancestors 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public static function invalidTokenDataProvider(): \Generator
    {
        yield 'empty' => [''];
        yield 'malformed' => ['invalid'];
        yield 'foreign signature' => ['eyJhbGciOiJIUzI1NiJ9.eyJ0eXAiOiJwcmV2aWV3IiwiZXhwIjo5OTk5OTk5OTk5fQ.c2lnbmF0dXJl'];
    }

    #[Test]
    #[DataProvider('invalidTokenDataProvider')]
    public function invalidTokenFailsClosed(string $jwt): void
    {
        $response = $this->preview('https://first.example/', $jwt);

        $this->assertRejected('invalid', $response);
        self::assertStringNotContainsString('postMessage', (string)$response->getBody());
    }

    #[Test]
    public function expiredTokenShowsErrorPageAndTellsTheBackend(): void
    {
        $clock = new readonly class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('-1 hour');
            }
        };
        $jwt = new PreviewTokenService($clock)->sign($this->createProfile(), self::BACKEND, self::USER_AGENT, 1)->jwt;

        $response = $this->preview('https://first.example/hidden', $jwt);

        $this->assertRejected('expired', $response);
        self::assertStringContainsString('typo3:preview:expired', (string)$response->getBody());
        self::assertStringContainsString('"https://backend.example"', (string)$response->getBody());
        self::assertSame('frame-ancestors ' . self::BACKEND, $response->getHeaderLine('Content-Security-Policy'));
    }

    #[Test]
    public function tokenOfAnotherBrowserIsRefused(): void
    {
        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://first.example/?__t3ct=' . $this->sign())
                ->withHeader('Sec-Fetch-Dest', 'iframe')
                ->withHeader('User-Agent', 'curl/8.0')
        );

        $this->assertRejected('otherBrowser', $response);
    }

    #[Test]
    public function tokenIsRefusedOnASiteOutsideTheMountsOfTheEditor(): void
    {
        $response = $this->preview('http://second.example/', $this->signForEditor(), null);

        $this->assertRejected('invalid', $response);
        self::assertStringNotContainsString('postMessage', (string)$response->getBody());
        self::assertSame(200, $this->preview('http://second.example/', $this->sign(), null)->getStatusCode());
    }

    /**
     * A site on a host of the preview whose root lies outside the web mounts of the editor
     */
    #[Test]
    public function siteOnAHostOfThePreviewOutsideTheMountsOfTheEditorIsRefused(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 20, 'pid' => 0, 'title' => 'Shop', 'slug' => '/', 'is_siteroot' => 1, 'perms_everybody' => 15]);
        $this->setUpFrontendRootPage(20, [], ['config' => 'page = PAGE' . LF . 'page.10 = TEXT' . LF . 'page.10.value = Shop site']);
        $this->writeSiteConfiguration('shop', $this->buildSiteConfiguration(20, 'https://first.example/shop/'), [$this->buildDefaultLanguageConfiguration('EN', '/')]);

        $response = $this->preview('https://first.example/shop/', $this->signForEditor());

        $this->assertRejected('outOfScope', $response);
        self::assertStringContainsString('typo3:preview:expired', (string)$response->getBody());
        self::assertStringContainsString('Shop site', (string)$this->preview('https://first.example/shop/', $this->sign())->getBody());
    }

    #[Test]
    public function tokenIsRefusedOnTheHostOfALanguageWithoutAccess(): void
    {
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
            $this->buildLanguageConfiguration('DE', 'https://first.de/'),
        ]);

        $this->assertRejected('invalid', $this->preview('https://first.de/', $this->signForEditor()));
        self::assertSame(200, $this->preview('https://first.example/', $this->signForEditor())->getStatusCode());
        self::assertSame(200, $this->preview('https://first.de/', $this->sign())->getStatusCode());
    }

    #[Test]
    public function siteWithoutHostIsPreviewedOnTheHostOfTheBackendOnly(): void
    {
        $this->writeSiteConfiguration('second', $this->buildSiteConfiguration(7, '/second/'), [$this->buildDefaultLanguageConfiguration('EN', '/')]);
        $jwt = $this->sign();

        self::assertSame(200, $this->preview('https://backend.example/second/', $jwt)->getStatusCode());
        $this->assertRejected('invalid', $this->preview('https://elsewhere.example/second/', $jwt));
    }

    #[Test]
    public function siteIsRefusedOnTheNextRequestOnceTheEditorLosesItsMount(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['db_mountpoints' => '1,7'], ['uid' => 1]);
        $jwt = $this->signForEditor();
        self::assertSame(200, $this->preview('http://second.example/', $jwt, null)->getStatusCode());

        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['db_mountpoints' => '1'], ['uid' => 1]);

        $this->assertRejected('invalid', $this->preview('http://second.example/', $jwt, null));
    }

    #[Test]
    public function tokenSignedBeforeASiteWasCreatedIsValidOnIt(): void
    {
        $jwt = $this->sign();
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 20, 'pid' => 0, 'title' => 'Third', 'slug' => '/', 'is_siteroot' => 1, 'perms_everybody' => 15]);
        $this->setUpFrontendRootPage(20, [], ['config' => 'page = PAGE' . LF . 'page.10 = TEXT' . LF . 'page.10.value = Third site']);
        $this->writeSiteConfiguration('third', $this->buildSiteConfiguration(20, 'https://third.example/'), [$this->buildDefaultLanguageConfiguration('EN', '/')]);

        $response = $this->preview('https://third.example/', $jwt);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Third site', (string)$response->getBody());
    }

    public static function topLevelNavigationDataProvider(): \Generator
    {
        yield 'document' => [['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate']];
        yield 'document without mode' => [['Sec-Fetch-Dest' => 'document']];
        yield 'navigation of another destination' => [['Sec-Fetch-Dest' => 'embed', 'Sec-Fetch-Mode' => 'navigate']];
    }

    /**
     * A new tab, a target _blank or _top opens the address without the token: no
     * backend user is built and the answer is the same for any token.
     */
    #[Test]
    #[DataProvider('topLevelNavigationDataProvider')]
    public function topLevelNavigationIsRedirectedToTheAddressWithoutToken(array $headers): void
    {
        foreach ([$this->sign(), 'invalid', ''] as $jwt) {
            $GLOBALS['BE_USER'] = null;
            $response = $this->processPreviewAccess('https://first.example/page?flag&q=a+b&__t3ct=' . $jwt . '&id=1&id=2', $jwt, $headers);

            self::assertSame(303, $response->getStatusCode());
            self::assertSame('https://first.example/page?flag&q=a+b&id=1&id=2', $response->getHeaderLine('Location'));
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('', (string)$response->getBody());
            self::assertNull($GLOBALS['BE_USER']);
        }
    }

    #[Test]
    public function topLevelNavigationOfTheSiteIsRedirectedWithoutToken(): void
    {
        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://first.example/hidden?a=1&__t3ct=' . $this->sign())
                ->withHeader('User-Agent', self::USER_AGENT)
                ->withHeader('Sec-Fetch-Dest', 'document')
                ->withHeader('Sec-Fetch-Mode', 'navigate')
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://first.example/hidden?a=1', $response->getHeaderLine('Location'));
    }

    public static function requestsOfThePreviewItselfDataProvider(): \Generator
    {
        yield 'fetch' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'same-origin']];
        yield 'script element' => [['Sec-Fetch-Dest' => 'script', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Site' => 'same-origin']];
        yield 'image element' => [['Sec-Fetch-Dest' => 'image', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Site' => 'same-origin']];
    }

    /**
     * Only a document of the site itself sends a same-origin request, and in a preview
     * that is the page in the frame, which has the token anyway.
     */
    #[Test]
    #[DataProvider('requestsOfThePreviewItselfDataProvider')]
    public function requestOfThePageInTheFrameIsShownWithTheToken(array $headers): void
    {
        $response = $this->previewWithHeaders('https://first.example/hidden', $this->sign(), $headers);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-2', (string)$response->getBody());
        self::assertStringNotContainsString('preview-navigation.js', (string)$response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public static function requestsFromElsewhereDataProvider(): \Generator
    {
        yield 'fetch of another site' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'cross-site']];
        yield 'fetch of another origin of the same site' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'same-site']];
        yield 'image of another site' => [['Sec-Fetch-Dest' => 'image', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Site' => 'cross-site']];
        yield 'request typed by the user' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'none']];
        yield 'no site named' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors']];
    }

    #[Test]
    #[DataProvider('requestsFromElsewhereDataProvider')]
    public function requestFromElsewhereIsRefused(array $headers): void
    {
        $response = $this->previewWithHeaders('https://first.example/hidden', $this->sign(), $headers);

        $this->assertRejected('notInFrame', $response);
        self::assertStringNotContainsString('Dummy 1-2', (string)$response->getBody());
    }

    #[Test]
    public function tokenInAFrameIsStillShown(): void
    {
        self::assertSame(200, $this->preview('https://first.example/hidden', $this->sign(), 'iframe')->getStatusCode());
    }

    #[Test]
    public function tokenWithoutFrameHeaderIsRefusedOnHttpsSite(): void
    {
        $response = $this->preview('https://first.example/', $this->sign(), null);

        $this->assertRejected('notInFrame', $response);
        self::assertStringContainsString('>This preview is only available in the backend.</p>', (string)$response->getBody());
    }

    #[Test]
    public function tokenWithoutFrameHeaderIsAcceptedOnPlainHttpSite(): void
    {
        $response = $this->preview('http://second.example/', $this->sign(), null);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The live frame of a workspace preview navigates between the sites of the editor
     */
    #[Test]
    public function visitorTokenWorksOnEverySiteTheEditorWhoOpenedItMayPreview(): void
    {
        $jwt = $this->signForVisitor();
        $first = (string)$this->preview('https://first.example/', $jwt)->getBody();

        self::assertStringContainsString('href="http://second.example/?__t3ct=' . $jwt . '"', $first);
        $second = $this->preview('http://second.example/', $jwt, null);
        self::assertSame(200, $second->getStatusCode());
        self::assertStringContainsString('Root 2', (string)$second->getBody());
        self::assertStringNotContainsString('Backend user', (string)$second->getBody());
        self::assertStringNotContainsString('typo3-preview-info', (string)$second->getBody());
    }

    #[Test]
    public function visitorTokenIsRefusedOnSitesTheEditorWhoOpenedItMayNotPreview(): void
    {
        $jwt = $this->signForVisitor(editorId: 2);

        self::assertStringContainsString('href="http://second.example/"', (string)$this->preview('https://first.example/', $jwt)->getBody());
        $response = $this->preview('http://second.example/', $jwt, null);
        $this->assertRejected('invalid', $response);
        self::assertStringNotContainsString('postMessage', (string)$response->getBody());
    }

    #[Test]
    public function visitorTokenOfAnEditorWhoIsNoLongerAvailableIsRefused(): void
    {
        $jwt = $this->signForVisitor(editorId: 2);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['disable' => 1], ['uid' => 2]);

        $this->assertRejected('userNotAvailable', $this->preview('https://first.example/', $jwt));
    }

    #[Test]
    public function pageOutsideTheMountsOfTheEditorIsRefused(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['db_mountpoints' => '5'], ['uid' => 1]);

        $this->assertRejected('outOfScope', $this->preview('https://first.example/hidden', $this->signForEditor()));
        self::assertSame(200, $this->preview('https://first.example/hidden', $this->sign())->getStatusCode());
        $external = $this->preview('https://first.example/external', $this->signForEditor());
        self::assertSame(303, $external->getStatusCode());
        self::assertSame('https://outside.example/', $external->getHeaderLine('Location'));
    }

    #[Test]
    public function pageTheEditorMayNotShowIsRefused(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 6]);

        $this->assertRejected('outOfScope', $this->preview('https://first.example/scheduled', $this->signForEditor()));
        self::assertSame(200, $this->preview('https://first.example/scheduled', $this->sign())->getStatusCode());
    }

    #[Test]
    public function disabledLanguageIsShownOnlyIfTheEditorHasAccessToIt(): void
    {
        $response = $this->preview('https://first.example/de/', $this->sign());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Root German', (string)$response->getBody());

        $this->assertRejected('outOfScope', $this->preview('https://first.example/de/', $this->signForEditor()));
        self::assertSame(404, $this->executeFrontendSubRequest(new InternalRequest('https://first.example/de/'))->getStatusCode());
    }

    #[Test]
    public function backendUserSectionIsShownToTheEditorOfTheToken(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 21, 'pid' => 1, 'title' => 'Backend user section', 'slug' => '/backend-user-section', 'doktype' => 6, 'perms_everybody' => 15]);

        $response = $this->preview('https://first.example/backend-user-section', $this->signForEditor());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Backend user section', (string)$response->getBody());
        self::assertSame(403, $this->executeFrontendSubRequest(new InternalRequest('https://first.example/backend-user-section'))->getStatusCode());
    }

    #[Test]
    public function backendUserSectionIsRefusedToAnEditorWhoMayNotShowIt(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 21, 'pid' => 1, 'title' => 'Backend user section', 'slug' => '/backend-user-section', 'doktype' => 6, 'perms_everybody' => 0]);

        $response = $this->preview('https://first.example/backend-user-section', $this->signForEditor());

        $this->assertRejected('outOfScope', $response);
        self::assertStringNotContainsString('Backend user section', (string)$response->getBody());
    }

    #[Test]
    public function tokenMakesTheEditorTheBackendUserWithoutSession(): void
    {
        $response = $this->preview('https://first.example/', $this->signForEditor());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Backend user 2', (string)$response->getBody());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(0, (int)$this->get(ConnectionPool::class)->getConnectionForTable('be_sessions')->count('*', 'be_sessions', []));
        self::assertStringContainsString('Backend user 1', (string)$this->preview('https://first.example/', $this->sign())->getBody());
    }

    public static function unavailableEditorDataProvider(): \Generator
    {
        yield 'disabled' => [['disable' => 1]];
        yield 'deleted' => [['deleted' => 1]];
        yield 'expired' => [['endtime' => 86400]];
        yield 'workspace without access' => [[], 1];
    }

    #[Test]
    #[DataProvider('unavailableEditorDataProvider')]
    public function unavailableEditorIsRefused(array $userChanges, int $workspace = 0): void
    {
        if ($userChanges !== []) {
            $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', $userChanges, ['uid' => 2]);
        }
        $jwt = $this->sign(new PreviewProfile($workspace, true, true, 0, []), userId: 2);

        $response = $this->preview('https://first.example/', $jwt);

        $this->assertRejected('userNotAvailable', $response);
        self::assertStringNotContainsString('Backend user', (string)$response->getBody());
    }

    #[Test]
    public function rightsOfTheEditorApplyAsTheyAreNow(): void
    {
        $jwt = $this->sign();
        self::assertSame(200, $this->preview('https://first.example/hidden', $jwt)->getStatusCode());

        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['admin' => 0, 'workspace_perms' => 1], ['uid' => 1]);

        // Without web mounts, no host is part of the preview any more
        $this->assertRejected('invalid', $this->preview('https://first.example/hidden', $jwt));
    }

    #[Test]
    public function requestedHiddenPageIsShownAsWithABackendLogin(): void
    {
        $onlyRequested = new PreviewProfile(0, false, false, 0, []);

        self::assertSame(200, $this->preview('https://first.example/hidden', $this->signForEditor($onlyRequested))->getStatusCode());
        self::assertStringNotContainsString('href="/hidden', (string)$this->preview('https://first.example/', $this->signForEditor($onlyRequested))->getBody());
        self::assertStringContainsString('href="/hidden', (string)$this->preview('https://first.example/', $this->signForEditor())->getBody());
    }

    #[Test]
    public function adminPanelIsShownInThePreviewFrame(): void
    {
        $this->openAdminPanelForEditor();

        self::assertStringContainsString('typo3-adminPanel', (string)$this->preview('https://first.example/', $this->signForEditor())->getBody());
        self::assertStringNotContainsString('typo3-adminPanel', (string)$this->preview('https://first.example/', $this->signForVisitor())->getBody());
    }

    #[Test]
    public function adminPanelSimulatesItsGroupForATokenWithoutLogin(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->insert('tt_content', ['uid' => 5, 'pid' => 1, 'header' => 'Members only', 'fe_group' => '5']);
        $this->openAdminPanelForEditor('admPanel.override.preview.simulateUserGroup = 5');

        self::assertStringContainsString('Members only', (string)$this->preview('https://first.example/', $this->signForEditor())->getBody());
    }

    #[Test]
    public function adminPanelSimulatesItsDateForATokenWithoutDate(): void
    {
        $future = time() + 86400;
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['starttime' => $future], ['uid' => 6]);
        self::assertSame(404, $this->preview('https://first.example/scheduled', $this->signForEditor($this->createProfileWithoutSimulation()))->getStatusCode());

        $this->openAdminPanelForEditor('admPanel.override.preview.simulateDate = ' . ($future + 60));

        self::assertSame(200, $this->preview('https://first.example/scheduled', $this->signForEditor($this->createProfileWithoutSimulation()))->getStatusCode());
    }

    /**
     * As for ADMCMD_ parameters on main, the open admin panel sets the visibility completely
     */
    #[Test]
    public function openAdminPanelDecidesTheVisibilityOverTheToken(): void
    {
        $withHiddenContent = new PreviewProfile(0, false, true, 0, []);
        self::assertStringContainsString('Hidden content', (string)$this->preview('https://first.example/', $this->signForEditor($withHiddenContent))->getBody());

        $this->openAdminPanelForEditor('admPanel.override.preview.showHiddenRecords = 0');
        self::assertStringNotContainsString('Hidden content', (string)$this->preview('https://first.example/', $this->signForEditor($withHiddenContent))->getBody());

        $this->openAdminPanelForEditor('admPanel.override.preview.showHiddenRecords = 1');
        self::assertStringContainsString('Hidden content', (string)$this->preview('https://first.example/', $this->signForEditor($this->createProfileWithoutSimulation()))->getBody());
    }

    #[Test]
    public function groupAndDateOfTheTokenTakePrecedenceOverTheAdminPanel(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_groups')
            ->insert('fe_groups', ['uid' => 6, 'pid' => 1, 'title' => 'Other group']);
        $future = time() + 86400;
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['starttime' => $future], ['uid' => 6]);
        $this->openAdminPanelForEditor('admPanel.override.preview.simulateUserGroup = 6' . LF . 'admPanel.override.preview.simulateDate = ' . (time() - 3600));

        $withGroup = new PreviewProfile(0, true, true, 0, [5]);
        self::assertSame(200, $this->preview('https://first.example/hidden/protected', $this->signForEditor($withGroup))->getStatusCode());
        $withDate = new PreviewProfile(0, true, true, $future + 60, []);
        self::assertSame(200, $this->preview('https://first.example/scheduled', $this->signForEditor($withDate))->getStatusCode());
    }

    /**
     * Called directly, as a frontend request bootstraps the configuration again.
     */
    #[Test]
    public function backendIpMaskAppliesToTheEditor(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['IPmaskList'] = '10.1.2.3';
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('first');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new HtmlResponse('Rendered page');
            }
        };
        $request = static function (string $jwt) use ($site): ServerRequestInterface {
            $request = new ServerRequest('https://first.example/?__t3ct=' . $jwt, 'GET', null, ['Sec-Fetch-Dest' => 'iframe', 'User-Agent' => self::USER_AGENT], ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'first.example'])
                ->withQueryParams([PreviewTokenService::PARAMETER => $jwt])
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
                ->withAttribute('site', $site)
                ->withAttribute('language', $site->getDefaultLanguage());
            return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        };
        $previewAccess = $this->get(PreviewAccess::class);

        $this->assertRejected('userNotAvailable', $previewAccess->process($request($this->signForEditor()), $handler));
        self::assertSame('Rendered page', (string)$previewAccess->process($request($this->signForVisitor()), $handler)->getBody());
    }

    #[Test]
    public function visitorTokenShowsThePageAsAVisitorSeesIt(): void
    {
        $response = $this->preview('https://first.example/', $this->signForVisitor());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Visible content', (string)$response->getBody());
        self::assertStringNotContainsString('Hidden content', (string)$response->getBody());
        self::assertStringNotContainsString('Backend user', (string)$response->getBody());
        self::assertStringContainsString('preview-navigation.js', (string)$response->getBody());
        self::assertSame(404, $this->preview('https://first.example/hidden', $this->signForVisitor())->getStatusCode());
    }

    public static function visitorProfileAskingForMoreDataProvider(): \Generator
    {
        yield 'workspace' => [new PreviewProfile(1, false, false, 0, [])];
        yield 'hidden pages' => [new PreviewProfile(0, true, false, 0, [])];
        yield 'hidden content' => [new PreviewProfile(0, false, true, 0, [])];
        yield 'date' => [new PreviewProfile(0, false, false, 1796083200, [])];
        yield 'login' => [new PreviewProfile(0, false, false, 0, [], true)];
        yield 'groups' => [new PreviewProfile(0, false, false, 0, [5])];
    }

    #[Test]
    #[DataProvider('visitorProfileAskingForMoreDataProvider')]
    public function tokenWithoutEditorAskingForMoreThanTheVisitorViewIsRefused(PreviewProfile $profile): void
    {
        $this->assertRejected('invalid', $this->preview('https://first.example/', $this->signForVisitor($profile)));
    }

    #[Test]
    public function groupsTheEditorMayNotReadAreSimulatedOnlyWhereThePageNamesThem(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->insert('tt_content', ['uid' => 5, 'pid' => 1, 'header' => 'Members only', 'fe_group' => '5']);
        $withGroup = new PreviewProfile(0, true, true, 0, [5]);

        $editorRoot = (string)$this->preview('https://first.example/', $this->signForEditor($withGroup))->getBody();
        self::assertStringNotContainsString('Members only', $editorRoot);

        $editorProtected = $this->preview('https://first.example/hidden/protected', $this->signForEditor($withGroup));
        self::assertSame(200, $editorProtected->getStatusCode());

        $adminRoot = (string)$this->preview('https://first.example/', $this->sign($withGroup))->getBody();
        self::assertStringContainsString('Members only', $adminRoot);
    }

    #[Test]
    public function tokenGroupsReplaceLegacySimulation(): void
    {
        $withGroup = new PreviewProfile(0, true, true, 0, [5]);
        $response = $this->preview('https://first.example/hidden/protected', $this->sign($withGroup));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-2-3', (string)$response->getBody());

        $response = $this->preview('https://first.example/hidden/protected?ADMCMD_simUser=5', $this->sign());
        self::assertNotSame(200, $response->getStatusCode());
    }

    #[Test]
    public function frontendLoginInTheFrameIsKeptIfTheTokenSimulatesNone(): void
    {
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 5, 'pid' => 1, 'header' => 'Members only', 'fe_group' => '5']);
        $content->insert('tt_content', ['uid' => 6, 'pid' => 1, 'header' => 'Content hidden at login', 'fe_group' => '-1']);
        $loggedIn = $this->logInFrontendUser();

        $root = (string)$this->preview('https://first.example/', $this->sign(), 'iframe', $loggedIn)->getBody();
        $protected = $this->preview('https://first.example/hidden/protected', $this->sign(), 'iframe', $loggedIn);

        self::assertStringContainsString('Members only', $root);
        self::assertStringNotContainsString('Content hidden at login', $root);
        self::assertSame(200, $protected->getStatusCode());
        self::assertStringContainsString('Dummy 1-2-3', (string)$protected->getBody());
    }

    #[Test]
    public function frontendLoginInTheFrameIsKeptForAnEditor(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->insert('tt_content', ['uid' => 5, 'pid' => 1, 'header' => 'Members only', 'fe_group' => '5']);

        $body = (string)$this->preview('https://first.example/', $this->signForEditor(), 'iframe', $this->logInFrontendUser())->getBody();

        self::assertStringContainsString('Members only', $body);
    }

    #[Test]
    public function visitorTokenShowsThePageWithoutTheFrontendLoginOfTheRequest(): void
    {
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 5, 'pid' => 1, 'header' => 'Members only', 'fe_group' => '5']);
        $content->insert('tt_content', ['uid' => 6, 'pid' => 1, 'header' => 'Content hidden at login', 'fe_group' => '-1']);

        $body = (string)$this->preview('https://first.example/', $this->signForVisitor(), 'iframe', $this->logInFrontendUser())->getBody();

        self::assertStringNotContainsString('Members only', $body);
        self::assertStringContainsString('Content hidden at login', $body);
    }

    #[Test]
    public function simulatedGroupsReplaceTheGroupsOfALoggedInFrontendUser(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_groups')
            ->insert('fe_groups', ['uid' => 6, 'pid' => 1, 'title' => 'Other group']);
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 5, 'pid' => 1, 'header' => 'Members only', 'fe_group' => '5']);
        $content->insert('tt_content', ['uid' => 6, 'pid' => 1, 'header' => 'Other group only', 'fe_group' => '6']);
        $loggedIn = $this->logInFrontendUser();
        $withOtherGroup = new PreviewProfile(0, true, true, 0, [6]);

        $body = (string)$this->preview('https://first.example/', $this->sign($withOtherGroup), 'iframe', $loggedIn)->getBody();
        $protected = $this->preview('https://first.example/hidden/protected', $this->sign($withOtherGroup), 'iframe', $loggedIn);

        self::assertStringContainsString('Other group only', $body);
        self::assertStringNotContainsString('Members only', $body);
        self::assertNotSame(200, $protected->getStatusCode());
    }

    #[Test]
    public function tokenLoggedInWithoutGroupsShowsContentForAnyLogin(): void
    {
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 3, 'pid' => 1, 'header' => 'Content at any login', 'fe_group' => '-2']);
        $content->insert('tt_content', ['uid' => 4, 'pid' => 1, 'header' => 'Content hidden at login', 'fe_group' => '-1']);

        $loggedIn = new PreviewProfile(0, true, true, 0, [], true);
        $body = (string)$this->preview('https://first.example/', $this->sign($loggedIn))->getBody();
        self::assertStringContainsString('Content at any login', $body);
        self::assertStringNotContainsString('Content hidden at login', $body);

        $body = (string)$this->preview('https://first.example/', $this->sign())->getBody();
        self::assertStringNotContainsString('Content at any login', $body);
        self::assertStringContainsString('Content hidden at login', $body);
    }

    #[Test]
    public function tokenDateIsSimulatedAndLegacyDateIsIgnored(): void
    {
        $future = time() + 86400;
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->update('pages', ['starttime' => $future], ['uid' => 6]);
        $withDate = new PreviewProfile(0, true, true, $future + 60, []);

        self::assertSame(200, $this->preview('https://first.example/scheduled', $this->sign($withDate))->getStatusCode());
        self::assertSame(404, $this->preview('https://first.example/scheduled?ADMCMD_simTime=' . ($future + 60), $this->sign())->getStatusCode());
    }

    #[Test]
    public function linksKeepTheTokenOnlyForHostsTheEditorMayPreview(): void
    {
        $jwt = $this->sign();
        $body = (string)$this->preview('https://first.example/', $jwt)->getBody();

        self::assertStringContainsString('href="/hidden?__t3ct=' . $jwt . '"', $body);
        self::assertStringContainsString('href="http://second.example/?__t3ct=' . $jwt . '"', $body);
        self::assertStringContainsString('href="https://outside.example/"', $body);

        $body = (string)$this->preview('https://first.example/', $this->signForEditor())->getBody();
        self::assertStringContainsString('href="http://second.example/"', $body);
    }

    #[Test]
    public function navigationScriptNamesTheHostsTheEditorMayPreviewNow(): void
    {
        self::assertSame(['first.example', 'second.example'], $this->getNavigationHosts($this->preview('https://first.example/', $this->sign())));
        self::assertSame(['first.example'], $this->getNavigationHosts($this->preview('https://first.example/', $this->signForEditor())));
        self::assertSame(['first.example', 'second.example'], $this->getNavigationHosts($this->preview('https://first.example/', $this->signForVisitor())));
        self::assertSame(['first.example'], $this->getNavigationHosts($this->preview('https://first.example/', $this->signForVisitor(editorId: 2))));

        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['db_mountpoints' => '1,7'], ['uid' => 1]);

        self::assertSame(['first.example', 'second.example'], $this->getNavigationHosts($this->preview('https://first.example/', $this->signForEditor())));
    }

    #[Test]
    public function tokenWithoutSimulationShowsAVisiblePageWithoutPreview(): void
    {
        $response = $this->preview('https://first.example/', $this->signForEditor($this->createProfileWithoutSimulation()));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('typo3-preview-info', (string)$response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function tokenWithoutSimulationKeepsThePageInTheFrame(): void
    {
        $jwt = $this->sign($this->createProfileWithoutSimulation());

        $body = (string)$this->preview('https://first.example/', $jwt)->getBody();

        self::assertStringContainsString('href="http://second.example/?__t3ct=' . $jwt . '"', $body);
        self::assertStringContainsString('preview-navigation.js', $body);
    }

    #[Test]
    public function requestedHiddenPageIsShownAsPreview(): void
    {
        $response = $this->preview('https://first.example/hidden', $this->signForEditor($this->createProfileWithoutSimulation()));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('typo3-preview-info', (string)$response->getBody());
    }

    public static function simulationShownAsPreviewDataProvider(): \Generator
    {
        yield 'date' => [new PreviewProfile(0, false, false, time() + 3600, [])];
        yield 'frontend login' => [new PreviewProfile(0, false, false, 0, [], true)];
        yield 'frontend user group' => [new PreviewProfile(0, false, false, 0, [5])];
        yield 'hidden content' => [new PreviewProfile(0, false, true, 0, [])];
        yield 'all hidden pages' => [new PreviewProfile(0, true, false, 0, [])];
    }

    #[Test]
    #[DataProvider('simulationShownAsPreviewDataProvider')]
    public function simulationOfTheTokenIsShownAsPreview(PreviewProfile $profile): void
    {
        $response = $this->preview('https://first.example/', $this->sign($profile));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('typo3-preview-info', (string)$response->getBody());
    }

    #[Test]
    public function visitorTokenShowsThePageWithoutPreviewAndKeepsItInTheFrame(): void
    {
        $jwt = $this->signForVisitor();

        $body = (string)$this->preview('https://first.example/', $jwt)->getBody();

        self::assertStringNotContainsString('typo3-preview-info', $body);
        self::assertStringContainsString('preview-navigation.js', $body);
        self::assertStringContainsString('href="http://second.example/?__t3ct=' . $jwt . '"', $body);
    }

    #[Test]
    public function shortcutRedirectKeepsTheToken(): void
    {
        $jwt = $this->sign();
        $response = $this->preview('https://first.example/shortcut', $jwt);

        self::assertSame(307, $response->getStatusCode());
        self::assertSame('https://first.example/hidden?__t3ct=' . $jwt, $response->getHeaderLine('Location'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function redirectKeepsTheTokenOnlyForHostsOfTheToken(): void
    {
        $redirects = $this->get(ConnectionPool::class)->getConnectionForTable('sys_redirect');
        $redirects->insert('sys_redirect', ['uid' => 1, 'source_host' => '*', 'source_path' => '/old', 'target' => 't3://page?uid=2', 'target_statuscode' => 307]);
        $redirects->insert('sys_redirect', ['uid' => 2, 'source_host' => '*', 'source_path' => '/away', 'target' => 'https://outside.example/', 'target_statuscode' => 307]);
        $jwt = $this->sign();

        self::assertSame('https://first.example/hidden?__t3ct=' . $jwt, $this->preview('https://first.example/old', $jwt)->getHeaderLine('Location'));
        self::assertSame('https://outside.example/', $this->preview('https://first.example/away', $jwt)->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectToAnotherHostKeepsItsAddressAsItIs(): void
    {
        $target = 'https://sso.example.org/login?utm.source=x&id=1&id=2&flag&items[]=1&sig=ab%2bcd';
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 9, 'pid' => 1, 'title' => 'Login', 'slug' => '/login', 'doktype' => 3, 'link' => $target]);

        $response = $this->preview('https://first.example/login', $this->sign());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame($target, $response->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectToAHostOfThePreviewKeepsTheQueryAndAddsOnlyTheToken(): void
    {
        $target = 'https://second.example/?utm.source=x&id=1&id=2&flag&sig=ab%2bcd';
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 9, 'pid' => 1, 'title' => 'Second', 'slug' => '/to-second', 'doktype' => 3, 'link' => $target]);
        $jwt = $this->sign();

        $response = $this->preview('https://first.example/to-second', $jwt);

        self::assertSame($target . '&__t3ct=' . $jwt, $response->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectToAnotherHostLosesOnlyAToken(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 9, 'pid' => 1, 'title' => 'Leak', 'slug' => '/leak', 'doktype' => 3, 'link' => 'https://outside.example/?flag&__t3ct=leaked&q=a+b']);

        $response = $this->preview('https://first.example/leak', $this->sign());

        self::assertSame('https://outside.example/?flag&q=a+b', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function previewNeitherReadsNorWritesThePageCache(): void
    {
        $cacheRows = function (): int {
            $identifiers = $this->get(ConnectionPool::class)->getConnectionForTable('cache_pages')
                ->select(['identifier'], 'cache_pages')->fetchFirstColumn();
            return count(array_filter($identifiers, static fn(string $identifier): bool => !str_starts_with($identifier, 'redirects_')));
        };

        $this->preview('https://first.example/', $this->sign());
        self::assertSame(0, $cacheRows());

        $this->executeFrontendSubRequest(new InternalRequest('https://first.example/'));
        self::assertSame(1, $cacheRows());

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['title' => 'Changed root'], ['uid' => 1]);
        self::assertStringContainsString('Changed root', (string)$this->preview('https://first.example/', $this->sign())->getBody());
        self::assertStringNotContainsString('Changed root', (string)$this->executeFrontendSubRequest(new InternalRequest('https://first.example/'))->getBody());
    }

    private function preview(string $url, string $jwt, ?string $secFetchDest = 'iframe', ?InternalRequestContext $context = null): ResponseInterface
    {
        $request = new InternalRequest($url . (str_contains($url, '?') ? '&' : '?') . '__t3ct=' . $jwt)
            ->withHeader('User-Agent', self::USER_AGENT);
        if ($secFetchDest !== null) {
            $request = $request->withHeader('Sec-Fetch-Dest', $secFetchDest);
        }
        return $this->executeFrontendSubRequest($request, $context);
    }

    private function assertRejected(string $reason, ResponseInterface $response): void
    {
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('data-preview-rejection="' . $reason . '"', (string)$response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
    }

    private function previewWithHeaders(string $url, string $jwt, array $headers): ResponseInterface
    {
        $request = new InternalRequest($url . '?__t3ct=' . $jwt)->withHeader('User-Agent', self::USER_AGENT);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $this->executeFrontendSubRequest($request);
    }

    private function processPreviewAccess(string $url, string $jwt, array $headers): ResponseInterface
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('first');
        $request = new ServerRequest($url, 'GET', null, $headers + ['User-Agent' => self::USER_AGENT], ['HTTPS' => 'on', 'HTTP_HOST' => 'first.example'])
            ->withQueryParams([PreviewTokenService::PARAMETER => $jwt])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('A top-level navigation with a token reaches no page', 1794328963);
            }
        };
        return $this->get(PreviewAccess::class)->process($request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request)), $handler);
    }

    private function sign(?PreviewProfile $profile = null, int $userId = 1): string
    {
        return new PreviewTokenService(new SystemClock())
            ->sign($profile ?? $this->createProfile(), self::BACKEND, self::USER_AGENT, $userId)
            ->jwt;
    }

    /**
     * @return list<string>
     */
    private function getNavigationHosts(ResponseInterface $response): array
    {
        self::assertSame(1, preg_match('/data-preview-hosts="([^"]*)"/', (string)$response->getBody(), $matches));
        return json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function logInFrontendUser(): InternalRequestContext
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_users')
            ->insert('fe_users', ['uid' => 1, 'pid' => 1, 'username' => 'member', 'usergroup' => '5']);
        return new InternalRequestContext()->withFrontendUserId(1);
    }

    private function openAdminPanelForEditor(string $tsConfig = ''): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')
            ->update('be_groups', ['TSconfig' => 'admPanel.enable.preview = 1' . LF . $tsConfig], ['uid' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')
            ->update('be_users', ['uc' => serialize(['AdminPanel' => ['display_top' => true]])], ['uid' => 2]);
    }

    private function signForEditor(?PreviewProfile $profile = null): string
    {
        return $this->sign($profile, userId: 2);
    }

    private function signForVisitor(?PreviewProfile $profile = null, int $editorId = 1): string
    {
        return new PreviewTokenService(new SystemClock())
            ->sign($profile ?? new PreviewProfile(0, false, false, 0, []), self::BACKEND, self::USER_AGENT, $editorId, true)
            ->jwt;
    }

    private function createProfileWithoutSimulation(): PreviewProfile
    {
        return new PreviewProfile(0, false, false, 0, []);
    }

    private function createProfile(): PreviewProfile
    {
        return new PreviewProfile(0, true, true, 0, []);
    }
}
