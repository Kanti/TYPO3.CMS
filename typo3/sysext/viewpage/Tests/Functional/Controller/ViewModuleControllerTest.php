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

namespace TYPO3\CMS\Viewpage\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Context\PageContextFactory;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Event\AfterPagePreviewUriGeneratedEvent;
use TYPO3\CMS\Backend\Routing\Event\BeforePagePreviewUriGeneratedEvent;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\User\SharedUserPreferences;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Viewpage\Controller\ViewModuleController;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ViewModuleControllerTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const array LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'FR' => ['id' => 1, 'title' => 'French', 'locale' => 'fr_FR.UTF8'],
        'DE' => ['id' => 2, 'title' => 'German', 'locale' => 'de_DE.UTF8'],
    ];

    protected array $coreExtensionsToLoad = ['viewpage'];

    private BackendUserAuthentication $backendUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->backendUser);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages_languages.csv');

        $this->writeSiteConfiguration(
            'test-site',
            $this->buildSiteConfiguration(1, 'https://example.com/'),
            [
                $this->buildDefaultLanguageConfiguration('EN', '/'),
                $this->buildLanguageConfiguration('FR', '/fr/', ['EN']),
                $this->buildLanguageConfiguration('DE', '/de/', ['EN']),
            ]
        );
    }

    #[Test]
    public function controllerUsesLanguageFromPageContext(): void
    {
        $sharedPreferences = $this->get(SharedUserPreferences::class);
        $sharedPreferences->setPageLanguages($this->backendUser, 1, [1]); // French

        $request = $this->createRequest(1);

        $controller = $this->get(ViewModuleController::class);
        $response = $controller->handleRequest($request);

        $content = (string)$response->getBody();
        self::assertStringContainsString('French Root Page', $content);
    }

    #[Test]
    public function controllerFallsBackToDefaultLanguageWhenSelectedNotAvailable(): void
    {
        $sharedPreferences = $this->get(SharedUserPreferences::class);
        $sharedPreferences->setPageLanguages($this->backendUser, 1, [99]);

        $request = $this->createRequest(1);

        $controller = $this->get(ViewModuleController::class);
        $response = $controller->handleRequest($request);

        $content = (string)$response->getBody();
        self::assertStringContainsString('Default Root Page', $content);
    }

    #[Test]
    public function controllerFallsBackToDefaultLanguageWhenMultipleAreSelected(): void
    {
        $sharedPreferences = $this->get(SharedUserPreferences::class);
        $sharedPreferences->setPageLanguages($this->backendUser, 1, [1, 2]);

        $request = $this->createRequest(1);

        $controller = $this->get(ViewModuleController::class);
        $response = $controller->handleRequest($request);

        $content = (string)$response->getBody();
        self::assertStringContainsString('Default Root Page', $content);
    }

    #[Test]
    public function controllerUsesLanguagesArrayParameterInUrls(): void
    {
        $request = $this->createRequest(1);

        $controller = $this->get(ViewModuleController::class);
        $response = $controller->handleRequest($request);

        $content = (string)$response->getBody();

        // Language selector links should use 'languages' array parameter
        self::assertMatchesRegularExpression('/languages%5B0%5D=\d/', $content);
    }

    #[Test]
    public function controllerShowsEmptyPageForInvalidPageId(): void
    {
        // Page 5 does not exist
        $request = $this->createRequest(5);

        $controller = $this->get(ViewModuleController::class);
        $response = $controller->handleRequest($request);

        $content = (string)$response->getBody();

        self::assertStringContainsString('Please select a page with a valid page type', $content);
    }

    #[Test]
    public function controllerShowsEmptyPageForInvalidDoktype(): void
    {
        // Page 4 is a sysfolder (doktype 254)
        $request = $this->createRequest(4);

        $controller = $this->get(ViewModuleController::class);
        $response = $controller->handleRequest($request);

        $content = (string)$response->getBody();

        self::assertStringContainsString('Please select a page with a valid page type', $content);
    }

    #[Test]
    public function controllerRequiresPageContext(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763630591);

        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('test-site');
        $module = $this->get(ModuleProvider::class)->getModule('page_preview');
        $moduleData = new ModuleData('page_preview', ['language' => 0]);
        $route = new Route('/module/page-preview', [
            'packageName' => 'typo3/cms-viewpage',
            '_identifier' => 'page_preview',
        ]);

        $request = new ServerRequest('https://example.com/typo3/')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', $site)
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', $moduleData)
            ->withQueryParams(['id' => 1]);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $controller = $this->get(ViewModuleController::class);
        $controller->handleRequest($request);
    }

    #[Test]
    public function visiblePageIsShownInTheFrameWithAPreviewToken(): void
    {
        $settings = $this->previewSettingsOf($this->get(ViewModuleController::class)->handleRequest($this->createRequest(1)));

        $this->assertFrameWithPreviewToken($settings);
    }

    #[Test]
    public function hiddenPageIsShownInTheFrameWithAPreviewToken(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 1]);

        $settings = $this->previewSettingsOf($this->get(ViewModuleController::class)->handleRequest($this->createRequest(1)));

        $this->assertFrameWithPreviewToken($settings);
    }

    #[Test]
    public function pageOnTheHostOfTheBackendIsShownInTheFrameWithAPreviewToken(): void
    {
        $settings = $this->previewSettingsOf($this->get(ViewModuleController::class)->handleRequest($this->createRequest(1, [], 'example.com')));

        $this->assertFrameWithPreviewToken($settings);
    }

    #[Test]
    public function frameIsLoadedWithTheCookiesOfTheBrowserInEveryBrowser(): void
    {
        $content = (string)$this->get(ViewModuleController::class)->handleRequest($this->createRequest(1))->getBody();

        self::assertMatchesRegularExpression('/<iframe[^>]*id="tx_viewpage_iframe"/', $content);
        self::assertDoesNotMatchRegularExpression('/<iframe[^>]*credentialless/', $content);
    }

    #[Test]
    public function viewButtonOpensTheSiteDirectlyWhereThePreviewNeedsNoToken(): void
    {
        $content = (string)$this->get(ViewModuleController::class)->handleRequest($this->createRequest(1))->getBody();

        self::assertStringNotContainsString('/typo3/preview?', $content);
    }

    #[Test]
    public function viewButtonOpensThePreviewPageWhereThePreviewNeedsAToken(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 1]);

        $content = (string)$this->get(ViewModuleController::class)->handleRequest($this->createRequest(1))->getBody();

        self::assertStringContainsString('/typo3/preview?', html_entity_decode($content));
    }

    #[Test]
    public function addressOutsideTheSitesOfTheInstallationIsShownInTheFrameAsItIs(): void
    {
        $container = $this->get('service_container');
        $container->set(
            'headless-preview-uri-listener',
            static function (BeforePagePreviewUriGeneratedEvent $event): void {
                $event->setPreviewUri(new Uri('https://headless.example/page?ADMCMD_simTime=1'));
            }
        );
        $container->get(ListenerProvider::class)->addListener(BeforePagePreviewUriGeneratedEvent::class, 'headless-preview-uri-listener');

        $response = $this->get(ViewModuleController::class)->handleRequest($this->createRequest(1));
        $settings = $this->previewSettingsOf($response);

        self::assertSame('https://headless.example/page?ADMCMD_simTime=1', $settings['url']);
        self::assertSame([], $settings['hosts']);
        self::assertSame('', $settings['renewUrl']);
        self::assertStringNotContainsString('not allowed', (string)$response->getBody());
    }

    #[Test]
    public function relativeAddressOutsideTheSitesOfTheInstallationIsShownOnTheBackendHost(): void
    {
        $container = $this->get('service_container');
        $container->set(
            'relative-preview-uri-listener',
            static function (AfterPagePreviewUriGeneratedEvent $event): void {
                $event->setPreviewUri(new Uri('/headless/page?a=1'));
            }
        );
        $container->get(ListenerProvider::class)->addListener(AfterPagePreviewUriGeneratedEvent::class, 'relative-preview-uri-listener');

        $settings = $this->previewSettingsOf($this->get(ViewModuleController::class)->handleRequest($this->createRequest(1)));

        self::assertSame('https://backend.example.org/headless/page?a=1', $settings['url']);
        self::assertSame([], $settings['hosts']);
        self::assertSame('', $settings['renewUrl']);
    }

    /**
     * The frame shows the site on its own origin, which the Content-Security-Policy of
     * the module has to allow as frame source.
     */
    #[Test]
    public function frameMayShowTheOriginsOfThePreview(): void
    {
        $this->get(ViewModuleController::class)->handleRequest($this->createRequest(1));

        self::assertSame(['https://example.com'], $this->getFrameSources());
    }

    /**
     * An address outside the sites of the installation is shown as it is, from its own
     * origin, which the Content-Security-Policy of the module has to allow instead.
     */
    #[Test]
    public function frameMayShowTheOriginOfAnAddressOutsideTheSitesOfTheInstallation(): void
    {
        $container = $this->get('service_container');
        $container->set(
            'headless-preview-uri-listener',
            static function (BeforePagePreviewUriGeneratedEvent $event): void {
                $event->setPreviewUri(new Uri('https://headless.example/page'));
            }
        );
        $container->get(ListenerProvider::class)->addListener(BeforePagePreviewUriGeneratedEvent::class, 'headless-preview-uri-listener');

        $this->get(ViewModuleController::class)->handleRequest($this->createRequest(1));

        self::assertSame(['https://headless.example/page'], $this->getFrameSources());
    }

    /**
     * @return list<string>
     */
    private function getFrameSources(): array
    {
        $sources = [];
        foreach ($this->get(PolicyRegistry::class)->getMutationCollections() as $collection) {
            foreach ($collection->mutations as $mutation) {
                if ($mutation->directive !== Directive::FrameSrc) {
                    continue;
                }
                foreach ($mutation->sources as $source) {
                    if ($source instanceof UriValue) {
                        $sources[] = (string)$source;
                    }
                }
            }
        }
        return $sources;
    }

    private function previewSettingsOf(ResponseInterface $response): array
    {
        self::assertSame(1, preg_match('/data-preview-settings="([^"]+)"/', (string)$response->getBody(), $matches), (string)$response->getBody());
        return json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertFrameWithPreviewToken(array $settings): void
    {
        self::assertStringStartsWith('https://example.com/', $settings['url']);
        self::assertNotSame('', $this->tokenOf($settings['url']));
        self::assertNotSame('', $settings['renewUrl']);
        self::assertContains('example.com', $settings['hosts']);
    }

    private function tokenOf(string $url): string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return (string)($query[PreviewTokenService::PARAMETER] ?? '');
    }

    private function createRequest(int $pageId, array $additionalParams = [], string $backendHost = 'backend.example.org'): ServerRequest
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('test-site');
        $module = $this->get(ModuleProvider::class)->getModule('page_preview');
        $moduleData = new ModuleData('page_preview', ['language' => 0]);
        $route = new Route('/module/page-preview', [
            'packageName' => 'typo3/cms-viewpage',
            '_identifier' => 'page_preview',
        ]);

        $queryParams = array_merge(['id' => $pageId], $additionalParams);

        $request = new ServerRequest('https://' . $backendHost . '/typo3/', 'GET', null, [], ['HTTP_HOST' => $backendHost, 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', $site)
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', $moduleData)
            ->withQueryParams($queryParams);

        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $GLOBALS['TYPO3_REQUEST'] = $request;
        $pageContextFactory = $this->get(PageContextFactory::class);
        $pageContext = $pageContextFactory->createFromRequest($request, $pageId, $this->backendUser);
        $request = $request->withAttribute('pageContext', $pageContext);

        return $request;
    }
}
