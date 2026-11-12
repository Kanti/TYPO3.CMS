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

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Controller\PreviewController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreviewControllerTest extends FunctionalTestCase
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
        self::assertSame(1, $token->userId);
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

    #[Test]
    public function addressOutsideTheSitesOfTheInstallationOpensAsItIs(): void
    {
        $response = $this->showPreviewPage('https://outside.example/page?ADMCMD_simTime=1&ADMCMD_simUser=5#top');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://outside.example/page?ADMCMD_simTime=1&ADMCMD_simUser=5#top', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function addressOutsideTheSitesOfTheInstallationLosesAPreviewToken(): void
    {
        $response = $this->showPreviewPage('https://outside.example/page?a=1&__t3ct=leaked');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://outside.example/page?a=1', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function editorWhoMayNotPreviewThePageGetsAMessage(): void
    {
        $response = $this->showPreviewPage('https://first.example/', 4711);

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

        $response = $this->get(PreviewController::class)->tokenAction(
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

    private function showPreviewPage(string $url, int $page = 1): ResponseInterface
    {
        $request = new ServerRequest('https://backend.example/typo3/preview', 'GET', null, [], ['HTTP_HOST' => 'backend.example', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/preview', []))
            ->withQueryParams(['url' => $url, 'page' => $page]);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $this->get(PreviewController::class)->showAction($request);
    }
}
