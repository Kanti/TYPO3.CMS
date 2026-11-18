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

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Clock\SystemClock;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Preview\PreviewMode;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Tests\TestPreviewEditMode\Controller\EditFrameController;

/**
 * An extension builds an edit mode on top of the preview: the fixture extension
 * test_preview_edit_mode turns it on where the preview token asks for the mode edit.
 * The token carries the mode signed, and the site exposes it in the aspect
 * frontend.preview.
 */
final class PreviewEditModeExampleTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
    private const BACKEND = 'https://backend.example';

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../Fixtures/Extensions/test_preview_edit_mode',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $pages->update('pages', ['slug' => '/'], ['uid' => 1]);
        $pages->update('pages', ['slug' => '/hidden', 'hidden' => 1], ['uid' => 2]);
        $backendUsers = $this->get(ConnectionPool::class)->getConnectionForTable('be_users');
        $backendUsers->insert('be_users', ['uid' => 1, 'pid' => 0, 'username' => 'admin', 'admin' => 1]);
        $backendUsers->insert('be_users', ['uid' => 2, 'pid' => 0, 'username' => 'editor', 'admin' => 0, 'usergroup' => '1', 'options' => 3, 'workspace_perms' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1', 'allowed_languages' => '0', 'workspace_perms' => 1]);
        $this->setUpFrontendRootPage(1, [], ['config' => 'page = PAGE' . LF . 'page.10 = TEXT' . LF . 'page.10.data = page:title']);
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
    }

    #[Test]
    public function editorTokenInTheModeEditTurnsOnTheEditMode(): void
    {
        $response = $this->preview('https://first.example/', $this->signForEditor(PreviewMode::Edit));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('on', $response->getHeaderLine('X-Preview-Edit-Mode'));
    }

    #[Test]
    public function editorTokenInTheDefaultModeKeepsTheEditModeOff(): void
    {
        $response = $this->preview('https://first.example/', $this->signForEditor());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Preview-Edit-Mode'));
    }

    /**
     * The site previews a requested hidden page for the editor of a token and sets the
     * aspect frontend.preview again for it. The mode of the token stays.
     */
    #[Test]
    public function editorTokenInTheModeEditKeepsTheEditModeOnAHiddenPage(): void
    {
        $profile = new PreviewProfile(workspace: 0, hiddenPages: false, hiddenContent: false, date: 0, groups: [], mode: PreviewMode::Edit);

        $response = $this->preview('https://first.example/hidden', $this->sign($profile));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-2', (string)$response->getBody());
        self::assertSame('on', $response->getHeaderLine('X-Preview-Edit-Mode'));
    }

    /**
     * A visitor token always shows the pages in the mode view. The site refuses one that
     * asks for the mode edit, see PreviewTokenServiceTest.
     */
    #[Test]
    public function visitorTokenKeepsTheEditModeOff(): void
    {
        $response = $this->preview('https://first.example/', $this->signForVisitor());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Preview-Edit-Mode'));
    }

    /**
     * The edit mode exists only through a preview token. A backend login shows the pages
     * in the mode view.
     */
    #[Test]
    public function backendLoginWithoutTokenKeepsTheEditModeOff(): void
    {
        $editor = $this->get(ConnectionPool::class)->getConnectionForTable('be_users')
            ->select(['*'], 'be_users', ['uid' => 2])->fetchAssociative();
        $cookies = [BackendUserAuthentication::getCookieName() => new BackendUserAuthentication()->createUserSession($editor)->getJwt()];

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/')->withCookieParams($cookies));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Root', (string)$response->getBody());
        self::assertFalse($response->hasHeader('X-Preview-Edit-Mode'));
    }

    #[Test]
    public function addressOfTheBackendRouteOpensTheFrameInTheEditMode(): void
    {
        $this->setUpBackendUser(2);
        $route = $this->get(Router::class)->getRoute('ajax_test_preview_edit_mode_url');
        self::assertSame(EditFrameController::class . '::urlAction', $route?->getOption('target'));
        $request = new ServerRequest(self::BACKEND . '/typo3/ajax/test-preview-edit-mode/url?id=1', 'GET', null, ['User-Agent' => self::USER_AGENT], ['HTTP_HOST' => 'backend.example', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams(['id' => 1]);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $this->get(EditFrameController::class)->urlAction($request);

        self::assertSame(200, $response->getStatusCode());
        $url = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['url'];
        self::assertStringStartsWith('https://first.example/?' . PreviewTokenService::PARAMETER . '=', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([PreviewTokenService::PARAMETER], array_keys($query));
        $token = $this->get(PreviewTokenService::class)->verify($query[PreviewTokenService::PARAMETER], self::USER_AGENT);
        self::assertFalse($token->isVisitorWithoutBackendUser());
        self::assertSame(PreviewMode::Edit, $token->profile->mode);
        $frontendResponse = $this->executeFrontendSubRequest(
            new InternalRequest($url)
                ->withHeader('User-Agent', self::USER_AGENT)
                ->withHeader('Sec-Fetch-Dest', 'iframe')
        );
        self::assertSame(200, $frontendResponse->getStatusCode());
        self::assertSame('on', $frontendResponse->getHeaderLine('X-Preview-Edit-Mode'));
    }

    private function preview(string $url, string $jwt): ResponseInterface
    {
        $request = new InternalRequest($url . (str_contains($url, '?') ? '&' : '?') . PreviewTokenService::PARAMETER . '=' . $jwt)
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withHeader('Sec-Fetch-Dest', 'iframe');
        return $this->executeFrontendSubRequest($request);
    }

    private function signForEditor(PreviewMode $mode = PreviewMode::View): string
    {
        return $this->sign(new PreviewProfile(workspace: 0, hiddenPages: true, hiddenContent: true, date: 0, groups: [], mode: $mode));
    }

    private function sign(PreviewProfile $profile): string
    {
        return new PreviewTokenService(new SystemClock())->sign($profile, self::BACKEND, self::USER_AGENT, 2)->jwt;
    }

    private function signForVisitor(): string
    {
        return new PreviewTokenService(new SystemClock())
            ->sign(PreviewProfile::visitor(), self::BACKEND, self::USER_AGENT, 1, true)
            ->jwt;
    }
}
