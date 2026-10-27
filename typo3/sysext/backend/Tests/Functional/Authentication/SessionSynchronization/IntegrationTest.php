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

namespace TYPO3\CMS\Backend\Tests\Functional\Authentication\SessionSynchronization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Backend\Controller\BackendController;
use TYPO3\CMS\Backend\Exception\BackendLockedException;
use TYPO3\CMS\Backend\Http\Application;
use TYPO3\CMS\Backend\Middleware\BackendRouteInitialization;
use TYPO3\CMS\Backend\Middleware\BackendUserAuthenticator;
use TYPO3\CMS\Backend\Middleware\FetchMetadataGuard;
use TYPO3\CMS\Backend\Middleware\ForcedHttpsBackendRedirector;
use TYPO3\CMS\Backend\Middleware\LockedBackendGuard;
use TYPO3\CMS\Backend\Middleware\SessionSynchronization;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\RouteAccess;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\CookieScope;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyProvider;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class IntegrationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['workspaces'];
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['features' => ['security.backend.enforceFetchMetadata' => true]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->get(SiteWriter::class)->write('target', [
            'rootPageId' => 1,
            'base' => 'https://target.example.org/',
            'languages' => [[
                'languageId' => 0,
                'title' => 'English',
                'locale' => 'en_US.UTF-8',
                'base' => '/',
                'enabled' => true,
            ]],
        ]);
    }

    #[Test]
    public function synchronizationBridgeRunsAfterAccessGuardsAndBeforeAuthentication(): void
    {
        $stack = array_reverse(array_values($this->get('backend.middlewares')->getArrayCopy()));
        $syncPosition = array_search(SessionSynchronization::class, $stack, true);
        self::assertIsInt($syncPosition);
        foreach ([LockedBackendGuard::class, ForcedHttpsBackendRedirector::class] as $guard) {
            $position = array_search($guard, $stack, true);
            self::assertIsInt($position);
            self::assertLessThan($syncPosition, $position);
        }
        foreach ([BackendRouteInitialization::class, FetchMetadataGuard::class, BackendUserAuthenticator::class] as $middleware) {
            $position = array_search($middleware, $stack, true);
            self::assertIsInt($position);
            self::assertGreaterThan($syncPosition, $position);
        }
    }

    #[Test]
    public function invalidBridgeRequestIsRejectedWithoutChangingExistingCookies(): void
    {
        $request = $this->createRequest('/typo3/session/synchronize/bridge', 'POST')
            ->withCookieParams(['be_typo_user' => 'existing-cookie-must-not-be-deleted'])
            ->withParsedBody(['stage' => 'bootstrap', 'code' => str_repeat('a', 64)]);

        $response = $this->get(Application::class)->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(['result' => 'failed'], json_decode((string)$response->getBody(), true));
    }

    #[Test]
    public function lockedBackendAlsoBlocksSynchronizationBridge(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'] = -1;
        $this->expectException(BackendLockedException::class);

        $this->get(Application::class)->handle(
            $this->createRequest('/typo3/session/synchronize/bridge', 'POST')
                ->withParsedBody(['stage' => 'bootstrap', 'code' => str_repeat('a', 64)])
        );
    }

    #[Test]
    public function mainPageInitializesSynchronizationAndResolvesItsControlRoute(): void
    {
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $request = $this->createRequest('/typo3/main')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/main', ['packageName' => 'typo3/cms-backend', '_identifier' => 'main']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $this->get(BackendController::class)->mainAction($request);
        $controlUri = $this->get(UriBuilder::class)->buildUriFromRoute('ajax_session_synchronization_control');
        preg_match_all('#<script[^>]*>\s*/\*\s*(.*?)\s*\*/\s*</script>#s', (string)$response->getBody(), $matches);
        $synchronization = [];
        foreach ($matches[1] as $json) {
            foreach (json_decode($json, true, 512, JSON_THROW_ON_ERROR) as $instruction) {
                if (($instruction['payload']['name'] ?? '') === '@typo3/backend/session-synchronization.js') {
                    $synchronization[] = $instruction['payload'];
                }
            }
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $synchronization);
        self::assertSame('initialize', $synchronization[0]['items'][0]['method']);
        self::assertSame((string)$controlUri, $synchronization[0]['items'][0]['args'][0]['endpoint']);
        self::assertSame('/typo3/ajax/session/synchronize/control', $controlUri->getPath());
    }

    #[Test]
    public function registeredCspListenerAllowsOnlyTargetBridgeOnMainPage(): void
    {
        $request = $this->createRequest('/typo3/main')
            ->withAttribute('route', new Route('/main', ['_identifier' => 'main']));

        $policy = $this->get(PolicyProvider::class)->provideFor(Scope::backend(), request: $request);

        self::assertTrue($policy->containsDirective(
            Directive::FrameSrc,
            new UriValue('https://target.example.org/typo3/session/synchronize/bridge')
        ));
        self::assertFalse($policy->containsDirective(Directive::FrameSrc, new UriValue('https://target.example.org')));
    }

    public static function authenticatedSourceRoutesDataProvider(): iterable
    {
        yield 'confirmation' => ['/typo3/session/synchronize/confirm'];
        yield 'receipt' => ['/typo3/session/synchronize/receipt'];
    }

    #[Test]
    #[DataProvider('authenticatedSourceRoutesDataProvider')]
    public function sourceRoutesRequireBackendAuthentication(string $path): void
    {
        $request = $this->createRequest($path, 'POST')->withParsedBody(['code' => str_repeat('a', 64)]);
        $route = $this->get(Router::class)->matchResult($request)->getRoute();
        self::assertSame(RouteAccess::AuthenticatedWithoutToken, $route->getAccess());
        $response = $this->get(Application::class)->handle(
            $request
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/typo3/login', $response->getHeaderLine('Location'));
    }

    public static function crossOriginSourceRequestsDataProvider(): iterable
    {
        foreach (['same-site', 'cross-site'] as $fetchSite) {
            foreach ([
                '/typo3/session/synchronize/confirm',
                '/typo3/session/synchronize/receipt',
                '/typo3/ajax/session/synchronize/control',
                '/typo3/logout',
            ] as $path) {
                yield $fetchSite . ' ' . $path => [$fetchSite, $path];
            }
        }
    }

    #[Test]
    #[DataProvider('crossOriginSourceRequestsDataProvider')]
    public function fetchMetadataStillProtectsSourceEndpointsAndOrdinaryBackendRoutes(string $fetchSite, string $path): void
    {
        $user = $this->setUpBackendUser(1);
        $request = $this->createRequest($path, 'POST')
            ->withCookieParams(['be_typo_user' => $this->sessionCookie($user)])
            ->withParsedBody(['code' => str_repeat('a', 64), 'action' => 'next'])
            ->withHeader('Origin', $fetchSite === 'same-site' ? 'https://target.example.org' : 'https://other.example.net')
            ->withHeader('Sec-Fetch-Site', $fetchSite);

        $response = $this->get(Application::class)->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Cross-origin request denied', $response->getReasonPhrase());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('be_session_transfer')->count('*', 'be_session_transfer', []));
    }

    #[Test]
    public function sourceConfirmationCannotBypassAnOutstandingMfaChallenge(): void
    {
        $user = $this->setUpBackendUser(1);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update(
            'be_users',
            ['mfa' => json_encode(['totp' => ['active' => true, 'secret' => 'JBSWY3DPEHPK3PXP']], JSON_THROW_ON_ERROR)],
            ['uid' => 1],
            ['mfa' => Connection::PARAM_LOB]
        );
        $user->setAndSaveSessionData('mfa', null);

        $response = $this->get(Application::class)->handle(
            $this->createRequest('/typo3/session/synchronize/confirm', 'POST')
                ->withCookieParams(['be_typo_user' => $this->sessionCookie($user)])
                ->withParsedBody(['code' => str_repeat('a', 64)])
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/typo3/auth/mfa', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('be_session_transfer')->count('*', 'be_session_transfer', []));
    }

    public static function roundTripDataProvider(): iterable
    {
        yield 'same-site frame with no target login' => ['frame', 'https://target.example.org', 0, 'synchronized'];
        yield 'cross-site navigation with no target login' => ['navigation', 'https://target.example.net', 0, 'synchronized'];
        yield 'cross-site navigation replaces same-user login' => ['navigation', 'https://target.example.net', 1, 'synchronized'];
        yield 'cross-site navigation preserves another user' => ['navigation', 'https://target.example.net', 2, 'skipped'];
    }

    #[Test]
    #[DataProvider('roundTripDataProvider')]
    public function completeRoundTripUsesTheAuthenticatedSourceAndPreservesTargetIdentityRules(
        string $mode,
        string $targetOrigin,
        int $existingUser,
        string $expectedOutcome,
    ): void {
        $sourceOrigin = 'https://source.example.org';
        $this->get(SiteWriter::class)->write('target', [
            'rootPageId' => 1,
            'base' => $targetOrigin . '/',
            'languages' => [[
                'languageId' => 0,
                'title' => 'English',
                'locale' => 'en_US.UTF-8',
                'base' => '/',
                'enabled' => true,
            ]],
        ]);
        $sourceUser = $this->setUpBackendUser(1);
        $sourceIdentifier = $sourceUser->getSession()->getIdentifier();
        $cookies = [$sourceOrigin => ['be_typo_user' => $this->sessionCookie($sourceUser)], $targetOrigin => []];
        $targetScope = new CookieScope(domain: parse_url($targetOrigin, PHP_URL_HOST), hostOnly: true, path: '/');
        if ($existingUser > 0) {
            if ($existingUser === 2) {
                $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->insert('be_users', [
                    'uid' => 2,
                    'username' => 'other-user',
                    'admin' => 1,
                ]);
            }
            $record = $this->getBackendUserRecordFromDatabase($existingUser);
            self::assertIsArray($record);
            $existingSession = GeneralUtility::makeInstance(BackendUserAuthentication::class)->createUserSession($record);
            self::assertNotSame($sourceIdentifier, $existingSession->getIdentifier());
            $cookies[$targetOrigin]['be_typo_user'] = $existingSession->getJwt($targetScope);
        }
        $originalTargetCookie = $cookies[$targetOrigin]['be_typo_user'] ?? null;
        $controlUrl = $sourceOrigin . $this->get(UriBuilder::class)->buildUriFromRoute('ajax_session_synchronization_control');
        $response = $this->browserPost($controlUrl, ['action' => 'next', 'mode' => $mode], $sourceOrigin, $cookies);
        self::assertSame(200, $response->getStatusCode());
        $task = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        // Navigation follows a failed frame probe in normal backend operation.
        if ($mode === 'navigation' && $task === null) {
            $response = $this->browserPost($controlUrl, ['action' => 'next', 'mode' => 'frame'], $sourceOrigin, $cookies);
            $probe = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($probe);
            $this->browserPost($controlUrl, ['action' => 'failed', 'flow' => $probe['flow'], 'result' => 'navigation-required'], $sourceOrigin, $cookies);
            $response = $this->browserPost($controlUrl, ['action' => 'next', 'mode' => 'navigation'], $sourceOrigin, $cookies);
            $task = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }
        self::assertIsArray($task);
        self::assertSame($targetOrigin, $task['target']);
        $this->assertSessionIdentifierIsNotPersistedInPlaintext($sourceIdentifier);
        $step = ['url' => $task['url'], 'fields' => $task['fields']];
        $documentOrigin = $sourceOrigin;
        $outcome = null;
        for ($index = 0; $index < 12; $index++) {
            $requestOrigin = $this->originOf($step['url']);
            // Cross-site top-level arrivals omit Strict cookies. The next request
            // from the loaded document includes them again.
            $sendCookies = $mode === 'frame' || $documentOrigin === $requestOrigin;
            $response = $this->browserPost($step['url'], $step['fields'], $documentOrigin, $cookies, $sendCookies);
            self::assertSame(200, $response->getStatusCode(), 'Failed synchronization step ' . $index);
            $this->assertSessionIdentifierIsNotPersistedInPlaintext($sourceIdentifier);
            if ($index === 0) {
                self::assertSame($originalTargetCookie, $cookies[$targetOrigin]['be_typo_user'] ?? null);
            }
            if (str_starts_with($response->getHeaderLine('Content-Type'), 'text/html')) {
                $body = (string)$response->getBody();
                self::assertStringNotContainsString($sourceIdentifier, $body);
                self::assertSame(1, preg_match('/const configuration = (\{.+\});\n/', $body, $matches));
                $configuration = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
                $step = $configuration['step'];
                $documentOrigin = $requestOrigin;
                continue;
            }
            $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (isset($result['next'])) {
                $step = $result['next'];
                continue;
            }
            $outcome = $result['result'] ?? null;
            break;
        }

        self::assertSame($expectedOutcome, $outcome);
        $targetIdentifier = UserSession::resolveIdentifierFromJwt($cookies[$targetOrigin]['be_typo_user'], $targetScope);
        if ($expectedOutcome === 'synchronized') {
            self::assertSame($sourceIdentifier, $targetIdentifier);
            self::assertNotSame($cookies[$sourceOrigin]['be_typo_user'], $cookies[$targetOrigin]['be_typo_user']);
        } else {
            self::assertSame($originalTargetCookie, $cookies[$targetOrigin]['be_typo_user']);
            self::assertNotSame($sourceIdentifier, $targetIdentifier);
        }
        $response = $this->browserPost($controlUrl, ['action' => 'status'], $sourceOrigin, $cookies);
        self::assertSame(200, $response->getStatusCode());
        $status = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($status['pending']);
        self::assertContains(['origin' => $targetOrigin, 'status' => $expectedOutcome], $status['results']);
        $this->assertSessionIdentifierIsNotPersistedInPlaintext($sourceIdentifier);
    }

    private function assertSessionIdentifierIsNotPersistedInPlaintext(string $identifier): void
    {
        foreach (['be_sessions', 'be_session_transfer'] as $table) {
            $records = $this->get(ConnectionPool::class)->getConnectionForTable($table)
                ->select(['*'], $table, [])->fetchAllAssociative();
            self::assertStringNotContainsString($identifier, serialize($records), 'Plaintext session identifier in ' . $table);
        }
    }

    private function browserPost(string $url, array $fields, string $initiatorOrigin, array &$cookies, bool $sendCookies = true): ResponseInterface
    {
        $uri = new Uri($url);
        $origin = $this->originOf($url);
        $serverParams = [
            'HTTP_HOST' => $uri->getAuthority(),
            'SERVER_NAME' => $uri->getHost(),
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->instancePath . '/index.php',
            'REQUEST_URI' => $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()),
            'REQUEST_METHOD' => 'POST',
        ];
        $_SERVER = array_replace($_SERVER, $serverParams);
        parse_str($uri->getQuery(), $query);
        $request = new ServerRequest($uri, 'POST', null, [], $serverParams)
            ->withQueryParams($query)
            ->withParsedBody($fields)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Origin', $initiatorOrigin)
            ->withHeader('Sec-Fetch-Site', $origin === $initiatorOrigin
                ? 'same-origin'
                : (str_ends_with($uri->getHost(), '.example.org') && str_ends_with(parse_url($initiatorOrigin, PHP_URL_HOST), '.example.org') ? 'same-site' : 'cross-site'))
            ->withCookieParams($sendCookies ? ($cookies[$origin] ?? []) : []);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $response = $this->get(Application::class)->handle($request);
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = Cookie::fromString($header);
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
                unset($cookies[$origin][$cookie->getName()]);
            } else {
                $cookies[$origin][$cookie->getName()] = $cookie->getValue();
            }
        }
        return $response;
    }

    private function originOf(string $url): string
    {
        $uri = new Uri($url);
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    private function sessionCookie(BackendUserAuthentication $user): string
    {
        return $user->getSession()->getJwt(new CookieScope(domain: 'source.example.org', hostOnly: true, path: '/'));
    }

    private function createRequest(string $path, string $method = 'GET'): ServerRequest
    {
        $serverParams = [
            'HTTP_HOST' => 'source.example.org',
            'SERVER_NAME' => 'source.example.org',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->instancePath . '/index.php',
            'REQUEST_URI' => $path,
            'REQUEST_METHOD' => $method,
        ];
        $request = new ServerRequest('https://source.example.org' . $path, $method, null, [], $serverParams)
            ->withHeader('Origin', 'https://source.example.org')
            ->withHeader('Sec-Fetch-Site', 'same-origin');
        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
