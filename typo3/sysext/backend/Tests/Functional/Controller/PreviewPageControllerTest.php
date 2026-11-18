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

namespace TYPO3\CMS\Backend\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Controller\PreviewPageController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreviewPageControllerTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function previewPageLoadsTheSiteInAFrameWithATokenOfTheEditor(): void
    {
        $response = $this->showPreviewPage('https://first.example/');

        self::assertSame(200, $response->getStatusCode());
        $content = (string)$response->getBody();
        self::assertStringContainsString('data-preview-frame', $content);
        self::assertSame(1, preg_match('/data-preview-settings="([^"]+)"/', $content, $matches));
        $settings = json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
        parse_str((string)parse_url($settings['url'], PHP_URL_QUERY), $query);
        $token = $this->get(PreviewTokenService::class)->verify($query[PreviewTokenService::PARAMETER], '');
        self::assertFalse($token->isVisitorWithoutBackendUser());
        self::assertSame(1, $token->editorId);
        self::assertFalse($token->profile->hiddenPages);
        self::assertFalse($token->profile->hiddenContent);
    }

    #[Test]
    public function frameIsLoadedWithTheCookiesOfTheBrowserInEveryBrowser(): void
    {
        $content = (string)$this->showPreviewPage('https://first.example/')->getBody();

        self::assertMatchesRegularExpression('/<iframe[^>]*data-preview-frame/', $content);
        self::assertDoesNotMatchRegularExpression('/<iframe[^>]*credentialless/', $content);
    }

    /**
     * An event listener may change the address of a preview to a host outside the preview,
     * such as a decoupled frontend. The backend signs the link it builds, and the preview
     * page opens such an address as it is.
     */
    #[Test]
    public function signedAddressOutsideTheSitesOfTheInstallationOpensAsItIs(): void
    {
        $url = 'https://outside.example/page?ADMCMD_simTime=1&ADMCMD_simUser=5#top';

        $response = $this->showPreviewPage($url, 1, $this->sign($url));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://outside.example/page?ADMCMD_simTime=1&ADMCMD_simUser=5#top', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function signedAddressOutsideTheSitesOfTheInstallationLosesAPreviewToken(): void
    {
        $url = 'https://outside.example/page?a=1&__t3ct=leaked';

        $response = $this->showPreviewPage($url, 1, $this->sign($url));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://outside.example/page?a=1', $response->getHeaderLine('Location'));
    }

    public static function unsignedAddressesOutsideTheSitesOfTheInstallationDataProvider(): \Generator
    {
        yield 'without a signature' => [null];
        yield 'with a wrong signature' => [str_repeat('0', 40)];
        yield 'with a signature that is no string' => [['0']];
        yield 'with the signature of another address outside' => [
            static fn(HashService $hashService): string => $hashService->hmac('https://outside.example/other', 'backend-preview-url'),
        ];
        yield 'with the signature of an address of the site' => [
            static fn(HashService $hashService): string => $hashService->hmac('https://first.example/', 'backend-preview-url'),
        ];
        yield 'with a signature for another purpose' => [
            static fn(HashService $hashService): string => $hashService->hmac('https://outside.example/', 'InlineContext'),
        ];
    }

    /**
     * The preview page is a GET route without a CSRF token. A link somebody else made
     * must not send the editor from the backend to any other host.
     */
    #[Test]
    #[DataProvider('unsignedAddressesOutsideTheSitesOfTheInstallationDataProvider')]
    public function unsignedAddressOutsideTheSitesOfTheInstallationGetsAMessage(mixed $hmac): void
    {
        if ($hmac instanceof \Closure) {
            $hmac = $hmac($this->get(HashService::class));
        }

        $response = $this->showPreviewPage('https://outside.example/', 1, $hmac);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The preview page keeps the address of the frame in its own address for a reload,
     * next to the signature of the address it was opened with. An address of the sites
     * needs no signature.
     */
    #[Test]
    public function addressOfTheSitesOpensWithTheSignatureOfAnotherAddress(): void
    {
        $response = $this->showPreviewPage('https://first.example/', 1, $this->sign('https://outside.example/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('https://first.example/?__t3ct=', $this->getFrameSettings($response)['url']);
    }

    #[Test]
    public function editorWhoMayNotPreviewThePageGetsAMessage(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '7']);
        $connectionPool->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 2, 'pid' => 0, 'username' => 'editor', 'admin' => 0, 'usergroup' => '1']);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(2));

        // Page 1 exists, but lies outside the only mount of the editor
        $response = $this->showPreviewPage('https://first.example/', 1);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The address of the frame comes from the query of the preview page. One that cannot
     * be parsed, as a hand-edited or truncated link may carry, gets the message instead
     * of an error, and no cache keeps it.
     */
    #[Test]
    public function malformedAddressGetsAMessage(): void
    {
        $response = $this->showPreviewPage('https://[first.example/');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The frame shows the site on its own origin, which the Content-Security-Policy of
     * the backend page has to allow as frame source, together with the other origins
     * the editor may navigate to in the frame.
     */
    #[Test]
    public function frameMayShowTheOriginsOfThePreview(): void
    {
        $this->writeSiteConfiguration('second', $this->buildSiteConfiguration(7, 'https://second.example:8443/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);

        $this->showPreviewPage('https://first.example/');

        $frameSources = [];
        foreach ($this->get(PolicyRegistry::class)->getMutationCollections() as $collection) {
            foreach ($collection->mutations as $mutation) {
                if ($mutation->directive !== Directive::FrameSrc) {
                    continue;
                }
                foreach ($mutation->sources as $source) {
                    if ($source instanceof UriValue) {
                        $frameSources[] = (string)$source;
                    }
                }
            }
        }
        self::assertSame(['https://first.example', 'https://second.example:8443'], $frameSources);
    }

    #[Test]
    public function renewalAnswersWithTheHostsOfThePreview(): void
    {
        $this->writeSiteConfiguration('second', $this->buildSiteConfiguration(7, 'https://second.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
        $settings = $this->getFrameSettings($this->showPreviewPage('https://first.example/'));
        parse_str((string)parse_url($settings['url'], PHP_URL_QUERY), $query);

        $response = $this->get(PreviewPageController::class)->tokenAction(
            $this->createBackendRequest()->withMethod('POST')->withParsedBody([
                'previewToken' => $query[PreviewTokenService::PARAMETER],
                'url' => 'https://second.example/',
                'page' => 7,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $result = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('https://second.example/?__t3ct=', $result['url']);
        self::assertSame(['first.example', 'second.example'], $result['hosts']);
        self::assertSame(['first.example', 'second.example'], $settings['hosts']);
        // Both sites run on HTTPS only, so the page around the frame never sends a token to their plain HTTP address
        self::assertSame(['first.example', 'second.example'], $result['httpsHosts']);
        self::assertSame(['first.example', 'second.example'], $settings['httpsHosts']);
    }

    /**
     * The page around the frame posts the address to renew a token for. One that cannot
     * be parsed gets the same refusal as an address outside the preview instead of an
     * error, and no cache keeps it.
     */
    #[Test]
    public function renewalOfAMalformedAddressIsRefused(): void
    {
        $settings = $this->getFrameSettings($this->showPreviewPage('https://first.example/'));
        parse_str((string)parse_url($settings['url'], PHP_URL_QUERY), $query);
        $request = $this->createBackendRequest()->withMethod('POST')->withParsedBody([
            'previewToken' => $query[PreviewTokenService::PARAMETER],
            'url' => 'https://[first.example/',
            'page' => 1,
        ]);

        $response = $this->get(PreviewPageController::class)->tokenAction($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['error' => 'denied'], json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function getFrameSettings(ResponseInterface $response): array
    {
        self::assertSame(1, preg_match('/data-preview-settings="([^"]+)"/', (string)$response->getBody(), $matches));
        return json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function createBackendRequest(): ServerRequest
    {
        $request = new ServerRequest('https://backend.example/typo3/ajax/preview/token', 'GET', null, [], ['HTTP_HOST' => 'backend.example', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    private function sign(string $url): string
    {
        return $this->get(HashService::class)->hmac($url, 'backend-preview-url');
    }

    private function showPreviewPage(string $url, int $page = 1, mixed $hmac = null): ResponseInterface
    {
        $query = ['url' => $url, 'page' => $page];
        if ($hmac !== null) {
            $query['hmac'] = $hmac;
        }
        $request = new ServerRequest('https://backend.example/typo3/preview', 'GET', null, [], ['HTTP_HOST' => 'backend.example', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/preview', []))
            ->withQueryParams($query);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $this->get(PreviewPageController::class)->showAction($request);
    }
}
