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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
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
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreviewControllerTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const array LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8', 'iso' => 'en'],
        'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8', 'iso' => 'de'],
    ];

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'features' => [
                'backend.previewToken' => true,
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_groups.csv');
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->workspace = 91;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(91));
        $this->writeSiteConfiguration(
            'main',
            $this->buildSiteConfiguration(1, 'https://example.com/'),
            [
                $this->buildDefaultLanguageConfiguration('EN', 'https://example.com/'),
                $this->buildLanguageConfiguration('DE', 'https://example.com/de/'),
            ],
        );
    }

    /**
     * The frame of the workspace simulates the groups of the live and of the draft version,
     * as the frontend checks both. Its token carries them, not its address. The live frame
     * shows the page as a visitor sees it, so it simulates no group.
     */
    public static function previewUrlsDataProvider(): array
    {
        return [
            'page without access restriction' => [
                2,
                ['path' => '/public', 'groups' => null],
                ['path' => '/public', 'groups' => null],
            ],
            'restricted page without draft version' => [
                3,
                ['path' => '/restricted', 'groups' => null],
                ['path' => '/restricted', 'groups' => '1'],
            ],
            'restricted page with draft version restricted to the same group' => [
                4,
                ['path' => '/restricted-modified', 'groups' => null],
                ['path' => '/restricted-modified', 'groups' => '1'],
            ],
            'page restricted in draft version only' => [
                5,
                ['path' => '/restricted-only-in-draft', 'groups' => null],
                ['path' => '/restricted-only-in-draft', 'groups' => '2'],
            ],
            'live and draft version restricted to different groups' => [
                6,
                ['path' => '/different-groups', 'groups' => null],
                ['path' => '/different-groups', 'groups' => '1,2'],
            ],
            'restricted page created in workspace' => [
                7,
                null,
                ['path' => '/new-restricted', 'groups' => '3'],
            ],
            'page shown at any login' => [
                10,
                ['path' => '/show-at-any-login', 'groups' => null],
                ['path' => '/show-at-any-login', 'groups' => '1'],
            ],
        ];
    }

    #[DataProvider('previewUrlsDataProvider')]
    #[Test]
    public function handleRequestRendersPreviewUrlsForPage(int $pageId, ?array $expectedLiveUrl, array $expectedDraftUrl): void
    {
        $content = $this->renderPreview($pageId);

        self::assertSame($expectedLiveUrl, $this->extractPreviewUrl($content, 'live'));
        self::assertSame($expectedDraftUrl, $this->extractPreviewUrl($content, 'workspace'));
    }

    /**
     * Switched off, the frames open their addresses as before the preview token: the
     * live frame with ADMCMD_prev=LIVE and the groups of the live version, the frame of
     * the workspace with ADMCMD_prev=IGNORE and the groups of both versions. The backend
     * login of the browser shows them.
     */
    public static function previewUrlsWithoutPreviewTokenDataProvider(): array
    {
        return [
            'page without access restriction' => [
                2,
                ['path' => '/public', 'ADMCMD_prev' => 'LIVE', 'ADMCMD_simUser' => null],
                ['path' => '/public', 'ADMCMD_prev' => 'IGNORE', 'ADMCMD_simUser' => null],
            ],
            'live and draft version restricted to different groups' => [
                6,
                ['path' => '/different-groups', 'ADMCMD_prev' => 'LIVE', 'ADMCMD_simUser' => '1'],
                ['path' => '/different-groups', 'ADMCMD_prev' => 'IGNORE', 'ADMCMD_simUser' => '1,2'],
            ],
            'restricted page created in workspace' => [
                7,
                null,
                ['path' => '/new-restricted', 'ADMCMD_prev' => 'IGNORE', 'ADMCMD_simUser' => '3'],
            ],
        ];
    }

    #[DataProvider('previewUrlsWithoutPreviewTokenDataProvider')]
    #[Test]
    public function framesOpenTheirAddressesWithoutPreviewTokenWhileThePreviewTokenIsSwitchedOff(int $pageId, ?array $expectedLiveUrl, array $expectedDraftUrl): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['backend.previewToken'] = false;

        $content = $this->renderPreview($pageId);

        self::assertSame($expectedLiveUrl, $this->extractPlainFrameUrl($content, 'live'));
        self::assertSame($expectedDraftUrl, $this->extractPlainFrameUrl($content, 'workspace'));
        self::assertContains('https://example.com', array_map(
            static fn(string $source): string => (string)parse_url($source, PHP_URL_SCHEME) . '://' . parse_url($source, PHP_URL_HOST),
            $this->getFrameSources()
        ));
    }

    public static function draftUrlOfTranslationCreatedInWorkspaceDataProvider(): array
    {
        return [
            'page without access restriction' => [
                8,
                ['path' => '/de/translated-in-draft-de', 'groups' => null],
            ],
            'restricted page' => [
                9,
                ['path' => '/de/restricted-translated-in-draft-de', 'groups' => '1'],
            ],
        ];
    }

    #[DataProvider('draftUrlOfTranslationCreatedInWorkspaceDataProvider')]
    #[Test]
    public function handleRequestRendersDraftUrlOfTranslationCreatedInWorkspace(int $pageId, array $expectedDraftUrl): void
    {
        $content = $this->renderPreview($pageId, 1);

        self::assertSame($expectedDraftUrl, $this->extractPreviewUrl($content, 'workspace'));
    }

    public static function draftUrlRendersDraftVersionInFrontendDataProvider(): array
    {
        return [
            'restricted page with draft version restricted to the same group' => [4, 'Restricted modified (draft)'],
            'page restricted in draft version only' => [5, 'Restricted only in draft (draft)'],
            'live and draft version restricted to different groups' => [6, 'Different groups (draft)'],
        ];
    }

    #[DataProvider('draftUrlRendersDraftVersionInFrontendDataProvider')]
    #[Test]
    public function draftUrlRendersDraftVersionInFrontend(int $pageId, string $expectedTitle): void
    {
        $this->setUpFrontendRootPage(1);
        $this->addTypoScriptToTemplateRecord(1, 'page = PAGE' . LF . 'page.10 = TEXT' . LF . 'page.10.field = title');
        $settings = $this->frameSettingsOf($this->renderPreviewResponse(['id' => $pageId]), 'wsPreviewSettings');

        // The token of the frame carries the workspace and the groups, the site needs no backend login
        $response = $this->executeFrontendSubRequest(
            new InternalRequest($settings['url'])->withHeader('Sec-Fetch-Dest', 'iframe'),
            new InternalRequestContext()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString($expectedTitle, (string)$response->getBody());
    }

    #[Test]
    public function sliderModeIsActiveIfNoPreviewModeIsStored(): void
    {
        $content = $this->renderPreview(1);

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

        $content = $this->renderPreview(1);

        self::assertStringContainsString('data-active-preview-mode="' . $previewMode . '"', $content);
        self::assertStringContainsString('typo3-workspace-preview-' . $previewMode, $content);
        self::assertStringNotContainsString('typo3-workspace-preview-slider', $content);
        self::assertStringContainsString('typo3-workspace-topbar-slider-hidden', $content);
    }

    #[Test]
    public function unknownStoredPreviewModeFallsBackToFirstAllowedMode(): void
    {
        $GLOBALS['BE_USER']->uc['workspaces']['previewMode'] = 'bogus';

        $content = $this->renderPreview(1);

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
        self::assertFalse($token->isVisitorWithoutBackendUser());
        self::assertSame(1, $token->editorId);
        self::assertSame(91, $token->profile->workspace);
    }

    /**
     * The live frame beside it shows the page as a visitor sees it: live, without hidden
     * records, simulation or backend user, on every site of the installation.
     */
    #[Test]
    public function liveFrameShowsThePageAsAVisitorSeesIt(): void
    {
        $settings = $this->frameSettingsOf($this->renderPreviewResponse(), 'livePreviewSettings');

        self::assertStringStartsWith('https://example.com/', $settings['url']);
        $token = $this->tokenOf($settings['url']);
        self::assertTrue($token->isVisitorWithoutBackendUser());
        self::assertSame(1, $token->editorId);
        self::assertTrue($token->profile->showsVisitorView());
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
     * The visitor token of the live frame is valid on every site of the installation, so
     * the backend lets the live frame load each of them, also those the editor may not
     * preview. The frame of the workspace stays on the sites of the editor.
     */
    #[Test]
    public function liveFrameMayShowEverySiteOfTheInstallation(): void
    {
        $this->setUpEditor(true);
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 50, 'pid' => 0, 'title' => 'Other root', 'slug' => '/', 'is_siteroot' => 1, 'perms_everybody' => 15]);
        $this->writeSiteConfiguration('other', $this->buildSiteConfiguration(50, 'https://other.example/'));

        $response = $this->renderPreviewResponse();

        self::assertEqualsCanonicalizing(['example.com', 'other.example'], $this->frameSettingsOf($response, 'livePreviewSettings')['hosts']);
        self::assertSame(['example.com'], $this->frameSettingsOf($response, 'wsPreviewSettings')['hosts']);
        self::assertContains('https://other.example', $this->getFrameSources());
    }

    /**
     * An address the preview cannot sign a token of the editor for, here the host of a
     * language the editor has no access to, opens in the frame of the workspace as it is:
     * without a token and without the backend asking for one later. The live frame gets a
     * visitor token there, which is valid on every site of the installation.
     */
    #[Test]
    public function addressOutsideThePreviewOfTheEditorIsShownInTheWorkspaceFrameWithoutToken(): void
    {
        $this->setUpEditor(true);
        $this->writeSiteConfiguration(
            'main',
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
        $workspaceSettings = $this->frameSettingsOf($response, 'wsPreviewSettings');
        self::assertStringStartsWith('https://de.example/', $workspaceSettings['url']);
        self::assertStringNotContainsString('__t3ct', $workspaceSettings['url']);
        self::assertSame('', $workspaceSettings['renewUrl']);
        self::assertSame([], $workspaceSettings['hosts']);
        self::assertContains('https://de.example/', $this->getFrameSources());
        $liveSettings = $this->frameSettingsOf($response, 'livePreviewSettings');
        self::assertStringStartsWith('https://de.example/', $liveSettings['url']);
        self::assertTrue($this->tokenOf($liveSettings['url'])->isVisitorWithoutBackendUser());
        self::assertContains('de.example', $liveSettings['hosts']);
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

    private function renderPreview(int $pageId = 1, int $languageId = 0): string
    {
        return (string)$this->renderPreviewResponse(['id' => $pageId, 'L' => $languageId])->getBody();
    }

    /**
     * The address of a frame and the frontend user groups it simulates, which its token
     * carries
     *
     * @return array{path: string, groups: ?string}|null
     */
    private function extractPreviewUrl(string $content, string $frame): ?array
    {
        $class = $frame === 'workspace' ? 't3js-workspace-view-workspace' : 't3js-workspace-view-live';
        if (!preg_match('/<iframe class="[^"]*' . $class . '"[^>]*data-preview-settings="([^"]+)"/', $content, $matches)) {
            return null;
        }
        $url = json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR)['url'];
        self::assertStringNotContainsString('ADMCMD_', $url);
        $groups = $this->tokenOf($url)->profile->groups;
        return [
            'path' => (string)parse_url($url, PHP_URL_PATH),
            'groups' => $groups === [] ? null : implode(',', $groups),
        ];
    }

    /**
     * The address of a frame without a preview token, which the frame opens as it is
     *
     * @return array{path: string, ADMCMD_prev: ?string, ADMCMD_simUser: ?string}|null
     */
    private function extractPlainFrameUrl(string $content, string $frame): ?array
    {
        $class = $frame === 'workspace' ? 't3js-workspace-view-workspace' : 't3js-workspace-view-live';
        if (!preg_match('/<iframe class="[^"]*' . $class . '"[^>]*data-preview-settings="([^"]+)"/', $content, $matches)) {
            return null;
        }
        $settings = json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $settings['renewUrl']);
        self::assertSame([], $settings['hosts']);
        parse_str((string)parse_url($settings['url'], PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey(PreviewTokenService::PARAMETER, $query);
        return [
            'path' => (string)parse_url($settings['url'], PHP_URL_PATH),
            'ADMCMD_prev' => $query['ADMCMD_prev'] ?? null,
            'ADMCMD_simUser' => $query['ADMCMD_simUser'] ?? null,
        ];
    }

    private function renderPreviewResponse(array $queryParams = []): ResponseInterface
    {
        $request = new ServerRequest('https://example.com/typo3/module/workspaces/preview', 'GET', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/workspaces/preview', ['packageName' => 'typo3/cms-workspaces', '_identifier' => 'workspace_previewcontrols']))
            ->withQueryParams(array_replace(['id' => 1], $queryParams));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(PreviewController::class)->handleRequest($request);
    }
}
