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

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Controller\PreviewController;
use TYPO3\CMS\Backend\Http\RequestHandler as BackendRequestHandler;
use TYPO3\CMS\Backend\Middleware\ContentSecurityPolicyHeaders as BackendContentSecurityPolicyHeaders;
use TYPO3\CMS\Backend\Routing\Router as BackendRouter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\SetCookieBehavior;
use TYPO3\CMS\Core\Http\SetCookieService;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Middleware\FrontendUserAuthenticator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreviewAccessTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const array LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8'],
    ];

    protected array $coreExtensionsToLoad = ['workspaces', 'adminpanel', 'felogin'];
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'preview-test-key-with-at-least-thirty-two-characters',
            'features' => ['security.frontend.enforceContentSecurityPolicy' => true],
            'caching' => ['cacheConfigurations' => ['pages' => ['backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class]]],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->get('cache.pages')->flush();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $pages->update('pages', ['slug' => '/'], ['uid' => 1]);
        $pages->update('pages', ['slug' => '/hidden', 'hidden' => 1], ['uid' => 2]);
        $pages->update('pages', ['slug' => '/protected', 'fe_group' => '5'], ['uid' => 3]);
        $pages->update('pages', ['slug' => '/external', 'doktype' => 3, 'link' => 'https://outside.example/'], ['uid' => 5]);
        $pages->update('pages', ['slug' => '/'], ['uid' => 7]);
        $pages->insert('pages', ['uid' => 8, 'pid' => 0, 'title' => 'Disabled language', 'slug' => '/', 'sys_language_uid' => 1, 'l10n_parent' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_groups')->insert('fe_groups', ['uid' => 5, 'pid' => 1, 'title' => 'Preview group']);
        $setup = 'page = PAGE' . LF
            . 'page.10 = TEXT' . LF . 'page.10.data = page:title' . LF
            . 'page.20 = TEXT' . LF . 'page.20.value = Other site' . LF . 'page.20.typolink.parameter = 7' . LF
            . 'page.30 = TEXT' . LF . 'page.30.value = External' . LF . 'page.30.typolink.parameter = 5' . LF;
        $this->setUpFrontendRootPage(1, [], ['config' => $setup]);
        $this->setUpFrontendRootPage(7, [], ['config' => $setup]);
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
            array_replace($this->buildLanguageConfiguration('DE', '/de/'), ['enabled' => false]),
        ]);
        $this->writeSiteConfiguration('second', $this->buildSiteConfiguration(7, 'https://second.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
    }

    #[Test]
    public function hiddenPageRendersAcrossDomainsWithoutCookiesAndDoesNotPopulatePageCache(): void
    {
        $token = $this->token();
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/hidden?_ps=' . $token));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-2', (string)$response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('', $response->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString('frame-ancestors https://backend.example', $response->getHeaderLine('Content-Security-Policy'));
        $live = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/hidden'));
        self::assertSame(404, $live->getStatusCode());
    }

    #[Test]
    public function previewNavigatesToOtherSiteAndDoesNotPutTokenOnExternalLinks(): void
    {
        $token = $this->token();
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $token));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('https://second.example/?_ps=' . $token, (string)$response->getBody());
        self::assertStringNotContainsString('https://outside.example/?_ps=', (string)$response->getBody());
        $other = $this->executeFrontendSubRequest(new InternalRequest('https://second.example/?_ps=' . $token));
        self::assertSame(200, $other->getStatusCode());
        self::assertSame('private, no-store', $other->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function groupsReplaceRealUserAndLegacySimulationParameters(): void
    {
        $token = $this->token(['groups' => [5]]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/protected?_ps=' . $token . '&ADMCMD_simUser=77&ADMCMD_prev=LIVE'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-2-3', (string)$response->getBody());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public static function loginSimulationProvider(): array
    {
        return ['anonymous' => [[], false], 'simulated group' => [[5], true]];
    }

    #[Test]
    #[DataProvider('loginSimulationProvider')]
    public function frontendLoginPluginRendersInStatelessPreview(array $groups, bool $loggedIn): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_template')->update('sys_template', [
            'config' => 'page = PAGE' . LF . 'page.10 = USER_INT' . LF
                . 'page.10.userFunc = TYPO3\CMS\Extbase\Core\Bootstrap->run' . LF
                . 'page.10.extensionName = Felogin' . LF . 'page.10.pluginName = Login',
        ], ['pid' => 1]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $this->token(['groups' => $groups])));
        self::assertSame(200, $response->getStatusCode());
        if ($loggedIn) {
            self::assertStringNotContainsString('id="tx-felogin-input-username"', (string)$response->getBody());
            self::assertStringContainsString('name="logintype" value="logout"', (string)$response->getBody());
        } else {
            self::assertStringContainsString('id="tx-felogin-input-username"', (string)$response->getBody());
            self::assertStringContainsString('id="tx-felogin-input-password"', (string)$response->getBody());
        }
        self::assertStringNotContainsString('fe_typo_user=', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('fe_sessions')->count('*', 'fe_sessions', []));
    }

    #[Test]
    public function previewUsesExistingSessionApiWithoutPersistingOrLoadingRealFrontendSession(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('fe_users');
        $record = ['uid' => 10, 'pid' => 1, 'username' => 'Real member', 'usergroup' => '6'];
        $connection->insert('fe_users', $record);
        $realUser = new FrontendUserAuthentication();
        $realUser->initializeUserSessionManager();
        $realSession = $realUser->createUserSession($record);
        $request = new ServerRequest('https://first.example/');
        $params = NormalizedParams::createFromRequest($request);
        $cookie = SetCookieService::create($realUser->name, 'FE')->setSessionCookie($realSession, $params);
        self::assertNotNull($cookie);
        $request = $request->withAttribute('normalizedParams', $params)
            ->withAttribute('frontend.preview.token', ['groups' => [5]])
            ->withCookieParams([$cookie->getName() => $cookie->getValue()]);
        $sessions = $this->get(ConnectionPool::class)->getConnectionForTable('fe_sessions');
        $beforeSessions = $sessions->select(['*'], 'fe_sessions')->fetchAllAssociative();
        $beforeUser = $connection->select(['*'], 'fe_users', ['uid' => 10])->fetchAssociative();
        $calls = 0;
        $callback = function (ServerRequestInterface $request) use (&$calls): ResponseInterface {
            $GLOBALS['TYPO3_REQUEST'] = $request;
            $user = $request->getAttribute('frontend.user');
            $queue = new FlashMessageQueue('preview-messages');
            self::assertCount(0, $queue->getAllMessages());
            self::assertNull($user->getSessionData('preview-data'));
            self::assertTrue($this->get(Context::class)->getPropertyFromAspect('frontend.user', 'isLoggedIn'));
            self::assertSame([0, -2, 5], $this->get(Context::class)->getPropertyFromAspect('frontend.user', 'groupIds'));
            $queue->enqueue(new FlashMessage('Preview message', storeInSession: true));
            self::assertSame('Preview message', $queue->getAllMessagesAndFlush()[0]->getMessage());
            self::assertCount(0, $queue->getAllMessages());
            $user->setAndSaveSessionData('preview-data', 'Only this request');
            self::assertSame('Only this request', $user->getSessionData('preview-data'));
            $user->start($request);
            self::assertTrue($user->createUserSession(['uid' => 10])->isAnonymous());
            $user->enforceNewSessionId();
            $user->logoff();
            self::assertSame(PHP_INT_MAX, $user->getUserId());
            self::assertSame('Only this request', $user->getSessionData('preview-data'));
            $user->setKey('user', 'preview-setting', 'Only this request');
            $user->storeSessionData();
            $user->writeUC();
            self::assertSame(SetCookieBehavior::None, $user->getCookieBehavior());
            $user->removeSessionData();
            self::assertNull($user->getSessionData('preview-data'));
            $user->setAndSaveSessionData('preview-data', 'Must not survive');
            $user->removeCookie();
            self::assertSame(SetCookieBehavior::None, $user->getCookieBehavior());
            ++$calls;
            return new HtmlResponse('Preview session API works');
        };
        $handler = new class ($callback) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $callback) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->callback)($request);
            }
        };
        foreach (['login', 'logout'] as $loginType) {
            $response = $this->get(FrontendUserAuthenticator::class)->process(
                $request->withMethod('POST')->withParsedBody(['logintype' => $loginType, 'user' => 'Real member', 'pass' => 'ignored']),
                $handler
            );
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        }
        self::assertSame(2, $calls);
        self::assertSame($beforeSessions, $sessions->select(['*'], 'fe_sessions')->fetchAllAssociative());
        self::assertSame($beforeUser, $connection->select(['*'], 'fe_users', ['uid' => 10])->fetchAssociative());
    }

    #[Test]
    public function disabledLanguageIsAvailableOnlyWithAuthorizedPreview(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/de/?_ps=' . $this->token()));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Disabled language', (string)$response->getBody());
        $live = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/de/'));
        self::assertSame(404, $live->getStatusCode());
    }

    #[Test]
    public function simulatedDateIsAppliedBeforePageAccessChecks(): void
    {
        $future = time() + 86400;
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['starttime' => $future], ['uid' => 2]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/hidden?_ps=' . $this->token(['date' => $future + 120])));
        self::assertSame(200, $response->getStatusCode());
    }

    public static function invalidTokenProvider(): array
    {
        return [
            'empty' => [''],
            'malformed' => ['invalid'],
            'signature' => ['eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjk5OTk5OTk5OTl9.invalid'],
        ];
    }

    #[Test]
    #[DataProvider('invalidTokenProvider')]
    public function invalidTokenFailsClosed(string $token): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $token));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function expiredTokenFailsClosed(): void
    {
        $service = $this->get(PreviewTokenService::class);
        $claims = $service->decode($this->token());
        $claims['iat'] = time() - 901;
        $claims['exp'] = $claims['iat'] + 900;
        $token = JWT::encode($claims, hash_hkdf('sha256', $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], 32, PreviewTokenService::class), 'HS256');
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $token));
        self::assertSame(403, $response->getStatusCode());
        $renewed = $service->decode($service->renew($token, $GLOBALS['BE_USER']));
        self::assertGreaterThan(time(), $renewed['exp']);
        self::assertSame($claims['scope'], $renewed['scope']);
    }

    #[Test]
    public function permissionRevocationInvalidatesExistingToken(): void
    {
        $token = $this->token();
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['disable' => 1], ['uid' => 1]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $token));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function scopeChecksSchemeHostPortAndPath(): void
    {
        $tokens = $this->get(PreviewTokenService::class);
        $claims = ['scope' => ['site' => [0 => 'https://first.example/sub/']]];
        self::assertTrue($tokens->allowsUri($claims, new Uri('https://first.example/sub/page')));
        foreach (['http://first.example/sub/', 'https://first.example:444/sub/', 'https://outside.example/sub/', 'https://first.example/submarine/', 'https://first.example/sub/%2e%2e/private', 'https://first.example@outside.example/sub/'] as $url) {
            self::assertFalse($tokens->allowsUri($claims, new Uri($url)), $url);
        }
    }

    #[Test]
    public function authenticatedRenewalPreservesProfileAndRejectsExternalTarget(): void
    {
        $token = $this->token(['groups' => [5], 'date' => 1799000000]);
        $request = new ServerRequest('https://backend.example/typo3/ajax/preview/renew', 'POST');
        $request = $request->withParsedBody(['previewToken' => $token, 'url' => 'https://second.example/']);
        $response = $this->get(PreviewController::class)->renewAction($request);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        parse_str(new Uri($data['url'])->getQuery(), $query);
        $claims = $this->get(PreviewTokenService::class)->decode($query['_ps']);
        self::assertSame([5], $claims['groups']);
        self::assertSame(1799000000, $claims['date']);
        self::assertSame(900, $claims['exp'] - $claims['iat']);
        $response = $this->get(PreviewController::class)->renewAction($request->withParsedBody(['previewToken' => $token, 'url' => 'https://outside.example/']));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function standalonePreviewRendersAuthenticatedToolbarAndIframe(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $route = $this->get(BackendRouter::class)->match('/preview');
        $request = new ServerRequest('https://backend.example/typo3/preview')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withQueryParams(['previewToken' => $this->token(), 'previewUrl' => 'https://second.example/']);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $response = $this->get(BackendContentSecurityPolicyHeaders::class)->process($request, $this->get(BackendRequestHandler::class));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString('https://first.example', $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('https://second.example', $response->getHeaderLine('Content-Security-Policy'));
        $document = new \DOMDocument();
        @$document->loadHTML((string)$response->getBody());
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//header[@id="typo3-preview-toolbar"]')->length);
        $frame = $xpath->query('//iframe[@id="typo3-preview-frame"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $frame);
        self::assertFalse($frame->hasAttribute('sandbox'));
        $uri = new Uri($frame->getAttribute('src'));
        self::assertSame('second.example', $uri->getHost());
        parse_str($uri->getQuery(), $query);
        self::assertSame(['_ps'], array_keys($query));
        self::assertSame(1, $this->get(PreviewTokenService::class)->decode($query['_ps'])['sub']);
        self::assertStringContainsString('preview-shell.js', (string)$response->getBody());
    }

    #[Test]
    public function standalonePreviewRejectsExternalTargetAndAnotherIssuer(): void
    {
        $route = $this->get(BackendRouter::class)->match('/preview');
        $request = new ServerRequest('https://backend.example/typo3/preview')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withQueryParams(['previewToken' => $this->token(), 'previewUrl' => 'https://outside.example/']);
        self::assertSame(403, $this->get(BackendRequestHandler::class)->handle($request)->getStatusCode());
        $request = $request->withQueryParams(['previewToken' => $this->token(), 'previewUrl' => 'https://first.example/']);
        $GLOBALS['BE_USER']->user['uid'] = 2;
        self::assertSame(403, $this->get(BackendRequestHandler::class)->handle($request)->getStatusCode());
    }

    public static function renewalRouteTokenProvider(): array
    {
        return [
            'valid route token' => [true, 200],
            'invalid route token' => [false, 401],
            'missing route token' => [null, 401],
        ];
    }

    #[Test]
    #[DataProvider('renewalRouteTokenProvider')]
    public function renewalPassesBackendRouteProtection(?bool $validRouteToken, int $expectedStatus): void
    {
        $previewToken = $this->token();
        $route = $this->get(BackendRouter::class)->match('/ajax/preview/renew');
        $request = new ServerRequest('https://backend.example/typo3/ajax/preview/renew', 'POST')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withParsedBody(['previewToken' => $previewToken, 'url' => 'https://second.example/']);
        $protection = $this->get(FormProtectionFactory::class)->createFromRequest($request);
        self::assertInstanceOf(BackendFormProtection::class, $protection);
        if ($validRouteToken !== null) {
            $request = $request->withQueryParams([
                'token' => $validRouteToken ? $protection->generateToken('route', $route->getOption('_identifier')) : 'invalid',
            ]);
        }
        $response = $this->get(BackendRequestHandler::class)->handle($request);
        self::assertSame($expectedStatus, $response->getStatusCode());
        if ($expectedStatus === 200) {
            $data = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            parse_str(new Uri($data['url'])->getQuery(), $query);
            $claims = $this->get(PreviewTokenService::class)->decode($query['_ps']);
            self::assertSame(900, $claims['exp'] - $claims['iat']);
        }
    }

    #[Test]
    public function previewJwtInReservedTokenFieldIsRejectedBeforeController(): void
    {
        $route = $this->get(BackendRouter::class)->match('/ajax/preview/renew');
        $request = new ServerRequest('https://backend.example/typo3/ajax/preview/renew', 'POST')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withParsedBody(['token' => $this->token(), 'url' => 'https://second.example/']);
        $protection = $this->get(FormProtectionFactory::class)->createFromRequest($request);
        $request = $request->withQueryParams(['token' => $protection->generateToken('route', $route->getOption('_identifier'))]);
        self::assertSame(401, $this->get(BackendRequestHandler::class)->handle($request)->getStatusCode());
    }

    #[Test]
    public function workspaceIsAppliedBeforeSlugResolution(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => 1, 'pid' => 0, 'title' => 'Draft', 'adminusers' => 'be_users_1']);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 9, 'pid' => 1, 'title' => 'Workspace page', 'slug' => '/draft-slug',
            't3ver_oid' => 2, 't3ver_wsid' => 1,
        ]);
        $token = $this->token(['workspace' => 1]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/draft-slug?_ps=' . $token));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Workspace page', (string)$response->getBody());
        self::assertSame(0, (int)$pool->getConnectionForTable('be_users')->select(['workspace_id'], 'be_users', ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function previewBypassesWarmCacheWithoutWritingItsRenderedPage(): void
    {
        $live = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/'));
        self::assertSame(200, $live->getStatusCode());
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['title' => 'Preview cache marker'], ['uid' => 1]);
        $preview = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $this->token()));
        self::assertStringContainsString('Preview cache marker', (string)$preview->getBody());
        $cached = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/'));
        self::assertStringNotContainsString('Preview cache marker', (string)$cached->getBody());
    }

    #[Test]
    public function removedPageMountPermissionDeniesPreviouslyAuthorizedPage(): void
    {
        $token = $this->token();
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', [
            'admin' => 0, 'db_mountpoints' => '2', 'workspace_perms' => 1,
        ], ['uid' => 1]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://second.example/?_ps=' . $token));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function disabledLanguageFlagIsEnforced(): void
    {
        $token = $this->token(['visibility' => [
            'hiddenPages' => true, 'hiddenContent' => false, 'scheduledRecords' => false, 'disabledLanguages' => false,
        ]]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/de/?_ps=' . $token));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function redirectsPreserveTokenOnlyOnEligibleDestinations(): void
    {
        $token = $this->token();
        $request = new ServerRequest('https://first.example/');
        $request = $request->withQueryParams(['_ps' => $token])
            ->withAttribute('site', $this->get(\TYPO3\CMS\Core\Site\SiteFinder::class)->getSiteByIdentifier('first'));
        foreach (['https://second.example/next', 'https://outside.example/'] as $destination) {
            $handler = new class ($destination) implements \Psr\Http\Server\RequestHandlerInterface {
                public function __construct(private string $destination) {}
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return new \TYPO3\CMS\Core\Http\RedirectResponse($this->destination);
                }
            };
            $response = $this->get(\TYPO3\CMS\Frontend\Middleware\PreviewAccess::class)->process($request, $handler);
            parse_str(new Uri($response->getHeaderLine('Location'))->getQuery(), $query);
            self::assertSame(str_contains($destination, 'second.example'), isset($query['_ps']));
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    #[Test]
    public function actualMiddlewareStackAppliesPreviewBeforeRoutingAndCaching(): void
    {
        $stack = array_reverse($this->get('frontend.middlewares')->getArrayCopy());
        $positions = array_flip(array_keys($stack));
        $preview = $positions['typo3/cms-frontend/preview-access'];
        foreach (['typo3/cms-frontend/backend-user-authentication', 'typo3/cms-frontend/base-redirect-resolver', 'typo3/cms-workspaces/preview', 'typo3/cms-frontend/page-resolver', 'typo3/cms-frontend/prepare-tsfe-rendering'] as $middleware) {
            self::assertLessThan($positions[$middleware], $preview, $middleware);
        }
        self::assertLessThan($positions['typo3/cms-frontend/preview-simulator'], $positions['typo3/cms-frontend/page-resolver']);
        self::assertLessThan($positions['typo3/cms-frontend/page-argument-validator'], $positions['typo3/cms-frontend/preview-simulator']);
    }

    #[Test]
    public function viewButtonBuilderProducesSignedFrontendUrl(): void
    {
        $previous = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://backend.example/typo3/')
            ->withAttribute('applicationType', \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_BE);
        try {
            $uri = \TYPO3\CMS\Backend\Routing\PreviewUriBuilder::create(2)->withModuleLoading(false)->buildUri();
            self::assertNotNull($uri);
            self::assertSame('first.example', $uri->getHost());
            parse_str($uri->getQuery(), $query);
            self::assertArrayHasKey('_ps', $query);
            self::assertArrayNotHasKey('ADMCMD_simTime', $query);
            $claims = $this->get(PreviewTokenService::class)->decode($query['_ps']);
            self::assertSame('https://backend.example', $claims['parent']);
        } finally {
            $GLOBALS['TYPO3_REQUEST'] = $previous;
        }
    }

    #[Test]
    public function previewDisregardsRealFrontendLoginGroupsAndBackendCookie(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_users')->insert('fe_users', ['uid' => 10, 'pid' => 1, 'username' => 'Member', 'usergroup' => '5']);
        $request = new InternalRequest('https://first.example/protected?_ps=' . $this->token());
        $context = new \TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext()->withFrontendUserId(10)->withBackendUserId(1);
        $response = $this->executeFrontendSubRequest($request, $context);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    #[Test]
    public function hiddenAndScheduledVisibilityFlagsAreApplied(): void
    {
        $flags = ['hiddenPages' => false, 'hiddenContent' => false, 'scheduledRecords' => false, 'disabledLanguages' => true];
        $hidden = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/hidden?_ps=' . $this->token(['visibility' => $flags])));
        self::assertSame(404, $hidden->getStatusCode());
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['starttime' => time() + 86400], ['uid' => 1]);
        $flags['scheduledRecords'] = true;
        $scheduled = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $this->token(['visibility' => $flags])));
        self::assertSame(200, $scheduled->getStatusCode());
    }

    #[Test]
    public function shortcutCannotEscapeCurrentPagePermissions(): void
    {
        $token = $this->token();
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->update('be_users', ['admin' => 0, 'db_mountpoints' => '2', 'workspace_perms' => 1], ['uid' => 1]);
        $pool->getConnectionForTable('pages')->update('pages', ['doktype' => 1, 'slug' => '/outside-mount'], ['uid' => 5]);
        $pool->getConnectionForTable('pages')->update('pages', ['doktype' => 4, 'slug' => '/shortcut', 'shortcut' => 5], ['uid' => 4]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/shortcut?_ps=' . $token));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function hiddenContentUsesTokenVisibility(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['pid' => 1, 'CType' => 'header', 'header' => 'Hidden content marker', 'hidden' => 1]);
        $templates = $pool->getConnectionForTable('sys_template');
        $setup = $templates->select(['config'], 'sys_template', ['pid' => 1])->fetchOne();
        $setup .= LF . 'page.40 = CONTENT' . LF . 'page.40.table = tt_content' . LF . 'page.40.select.pidInList = 1' . LF
            . 'page.40.renderObj = TEXT' . LF . 'page.40.renderObj.field = header' . LF;
        $templates->update('sys_template', ['config' => $setup], ['pid' => 1]);
        foreach ([false, true] as $hiddenContent) {
            $token = $this->token(['visibility' => ['hiddenPages' => true, 'hiddenContent' => $hiddenContent, 'scheduledRecords' => false, 'disabledLanguages' => true]]);
            $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $token));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($hiddenContent, str_contains((string)$response->getBody(), 'Hidden content marker'));
        }
    }

    #[Test]
    public function validSignatureDoesNotAuthorizeAnotherSite(): void
    {
        $claims = $this->get(PreviewTokenService::class)->decode($this->token());
        unset($claims['scope']['second']);
        $token = JWT::encode($claims, hash_hkdf('sha256', $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], 32, PreviewTokenService::class), 'HS256');
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://second.example/?_ps=' . $token));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function navigationBridgeIsIncludedWithoutPageRendererHeaders(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_template')->update('sys_template', [
            'config' => 'config.disableAllHeaderCode = 1' . LF . 'page = PAGE' . LF . 'page.10 = TEXT' . LF . 'page.10.value = Full HTML template',
        ], ['pid' => 1]);
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://first.example/?_ps=' . $this->token()));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Full HTML template', (string)$response->getBody());
        self::assertStringContainsString('data-typo3-preview-navigation', (string)$response->getBody());
        self::assertStringContainsString('preview-navigation.js', (string)$response->getBody());
        self::assertSame((string)strlen((string)$response->getBody()), $response->getHeaderLine('Content-Length'));
        self::assertSame(1, preg_match('/nonce="([^"]+)"/', (string)$response->getBody(), $nonce));
        self::assertStringContainsString("'nonce-" . $nonce[1] . "'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertCount(1, $response->getHeader('Content-Security-Policy'));
    }

    private function token(array $settings = []): string
    {
        return $this->get(PreviewTokenService::class)->issue($GLOBALS['BE_USER'], new Uri('https://backend.example/typo3/'), $settings);
    }
}
