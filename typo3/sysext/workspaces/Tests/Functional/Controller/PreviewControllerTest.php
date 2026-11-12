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

namespace TYPO3\CMS\Workspaces\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Workspaces\Controller\PreviewController;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreviewControllerTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const array LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8'],
    ];

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration(
            'test',
            $this->buildSiteConfiguration(1, 'https://example.com/'),
        );
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->workspace = 91;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function sliderModeIsActiveIfNoPreviewModeIsStored(): void
    {
        $content = $this->renderPreview();

        self::assertStringContainsString('data-active-preview-mode="slider"', $content);
        self::assertStringContainsString('typo3-workspace-preview-slider', $content);
        self::assertStringNotContainsString('typo3-workspace-topbar-slider-hidden', $content);
    }

    public static function storedPreviewModeIsActiveDataProvider(): array
    {
        return [
            'vbox' => ['vbox'],
            'hbox' => ['hbox'],
        ];
    }

    #[Test]
    #[DataProvider('storedPreviewModeIsActiveDataProvider')]
    public function storedPreviewModeIsActive(string $previewMode): void
    {
        $GLOBALS['BE_USER']->uc['workspaces']['previewMode'] = $previewMode;

        $content = $this->renderPreview();

        self::assertStringContainsString('data-active-preview-mode="' . $previewMode . '"', $content);
        self::assertStringContainsString('typo3-workspace-preview-' . $previewMode, $content);
        self::assertStringNotContainsString('typo3-workspace-preview-slider', $content);
        self::assertStringContainsString('typo3-workspace-topbar-slider-hidden', $content);
    }

    #[Test]
    public function unknownStoredPreviewModeFallsBackToFirstAllowedMode(): void
    {
        $GLOBALS['BE_USER']->uc['workspaces']['previewMode'] = 'bogus';

        $content = $this->renderPreview();

        self::assertStringContainsString('data-active-preview-mode="slider"', $content);
        self::assertStringNotContainsString('bogus', $content);
        self::assertStringNotContainsString('typo3-workspace-topbar-slider-hidden', $content);
    }

    #[Test]
    public function framesAreLoadedWithTheCookiesOfTheBrowserInEveryBrowser(): void
    {
        $content = $this->renderPreview();

        self::assertMatchesRegularExpression('/<iframe[^>]*t3js-workspace-view-workspace/', $content);
        self::assertDoesNotMatchRegularExpression('/<iframe[^>]*credentialless/', $content);
    }

    /**
     * The frame of the workspace shows the page as the editor previews it: in the
     * workspace, with a token of the editor that the site checks against the rights the
     * editor has then.
     */
    #[Test]
    public function workspaceFrameShowsThePageInTheWorkspaceWithATokenOfTheEditor(): void
    {
        $settings = $this->frameSettingsOf($this->renderPreviewResponse(), 'wsPreviewSettings');

        self::assertStringStartsWith('https://example.com/', $settings['url']);
        self::assertNotSame('', $settings['renewUrl']);
        self::assertSame(['example.com'], $settings['hosts']);
        $token = $this->tokenOf($settings['url']);
        self::assertSame(1, $token->userId);
        self::assertFalse($token->visitor);
        self::assertSame(91, $token->profile->workspace);
    }

    /**
     * The live frame beside it shows the page as a visitor sees it: live, without hidden
     * records, simulation or backend user, on the sites the editor who opened it may
     * preview.
     */
    #[Test]
    public function liveFrameShowsThePageAsAVisitorSeesIt(): void
    {
        $settings = $this->frameSettingsOf($this->renderPreviewResponse(), 'livePreviewSettings');

        self::assertStringStartsWith('https://example.com/', $settings['url']);
        $token = $this->tokenOf($settings['url']);
        self::assertTrue($token->visitor);
        self::assertNull($token->userId);
        self::assertSame(1, $token->editorId);
        self::assertTrue($token->profile->isVisitorView());
    }

    /**
     * Both frames show the site on its own origin, which the Content-Security-Policy of
     * the backend has to allow as frame source.
     */
    #[Test]
    public function framesMayShowTheOriginsOfThePreview(): void
    {
        $this->renderPreviewResponse();

        self::assertContains('https://example.com', $this->getFrameSources());
    }

    /**
     * An address the preview cannot sign a token for, here the host of a language the
     * editor has no access to, opens in the frames as it is: without a token and without
     * the backend asking for one later.
     */
    #[Test]
    public function addressOutsideThePreviewIsShownInTheFramesWithoutToken(): void
    {
        $this->setUpEditor(true);
        $this->writeSiteConfiguration(
            'test',
            $this->buildSiteConfiguration(1, 'https://example.com/'),
            [
                $this->buildDefaultLanguageConfiguration('EN', '/'),
                $this->buildLanguageConfiguration('DE', 'https://de.example/'),
            ]
        );
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 100, 'pid' => 0, 'title' => 'Wurzel', 'slug' => '/', 'sys_language_uid' => 1, 'l10n_parent' => 1, 'perms_everybody' => 15]);

        $response = $this->renderPreviewResponse(['L' => 1]);

        self::assertSame(200, $response->getStatusCode());
        foreach (['wsPreviewSettings', 'livePreviewSettings'] as $frame) {
            $settings = $this->frameSettingsOf($response, $frame);
            self::assertStringStartsWith('https://de.example/', $settings['url'], $frame);
            self::assertStringNotContainsString('__t3ct', $settings['url'], $frame);
            self::assertSame('', $settings['renewUrl'], $frame);
            self::assertSame([], $settings['hosts'], $frame);
        }
        self::assertContains('https://de.example/', $this->getFrameSources());
    }

    /**
     * An editor who may not preview the workspace gets a message, which no proxy or
     * browser cache keeps for the next one.
     */
    #[Test]
    public function editorWhoMayNotPreviewTheWorkspaceGetsAMessageThatIsNotCached(): void
    {
        $this->setUpEditor(false);

        $response = $this->renderPreviewResponse();

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    private function setUpEditor(bool $isMemberOfTheWorkspace): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1', 'allowed_languages' => '0', 'workspace_perms' => 1]);
        $connectionPool->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 2, 'pid' => 0, 'username' => 'editor', 'admin' => 0, 'usergroup' => '1', 'workspace_perms' => 1]);
        if ($isMemberOfTheWorkspace) {
            $connectionPool->getConnectionForTable('sys_workspace')->update('sys_workspace', ['members' => 'be_users_2'], ['uid' => 91]);
        }
        $backendUser = $this->setUpBackendUser(2);
        $backendUser->workspace = 91;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function frameSettingsOf(ResponseInterface $response, string $frame): array
    {
        $class = $frame === 'wsPreviewSettings' ? 't3js-workspace-view-workspace' : 't3js-workspace-view-live';
        self::assertSame(1, preg_match('/<iframe class="[^"]*' . $class . '"[^>]*data-preview-settings="([^"]+)"/', (string)$response->getBody(), $matches), (string)$response->getBody());
        return json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tokenOf(string $url): PreviewToken
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return $this->get(PreviewTokenService::class)->verify($query[PreviewTokenService::PARAMETER], '');
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

    private function renderPreview(): string
    {
        return (string)$this->renderPreviewResponse()->getBody();
    }

    private function renderPreviewResponse(array $queryParams = []): ResponseInterface
    {
        $request = new ServerRequest('https://example.com/typo3/workspace/preview-control', 'GET', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/workspace/preview-control', []))
            ->withQueryParams(array_replace(['id' => 1], $queryParams));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(PreviewController::class)->handleRequest($request);
    }
}
