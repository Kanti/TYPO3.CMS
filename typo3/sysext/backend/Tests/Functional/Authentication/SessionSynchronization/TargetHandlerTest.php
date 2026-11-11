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
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\BridgePage;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\OriginResolver;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\TargetHandler;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\TransferStore;
use TYPO3\CMS\Core\Authentication\IpLocker;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\CookieScope;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TargetHandlerTest extends FunctionalTestCase
{
    private const SOURCE = 'https://source.example.com';
    private const TARGET = 'https://target.example.net';
    private const BACKEND_PATH = '/installation/typo3/';
    private const SOURCE_SESSION = 'authenticated-source-session';
    private const COOKIE_NAME = 'be_typo_user';

    private TargetHandler $subject;
    private TransferStore $transferStore;
    private SessionManager $sessionManager;
    private ClockInterface $clock;
    private int $timestamp = 1793088000;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['EXEC_TIME'] = $this->timestamp;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'session-synchronization-functional-test';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['cookieDomain'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/typo3';
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieDomain'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName'] = self::COOKIE_NAME;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'strict';
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lifetime'] = 0;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['sessionTimeout'] = 300;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lockIP'] = 0;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lockIPv6'] = 0;

        $this->clock = self::createStub(ClockInterface::class);
        $this->clock->method('now')->willReturnCallback(fn(): \DateTimeImmutable => new \DateTimeImmutable('@' . $this->timestamp));
        $this->transferStore = new TransferStore($this->get(ConnectionPool::class), $this->clock, new CipherService(), new KeyFactory());
        $this->sessionManager = $this->get(SessionManager::class);
        $this->storeSession(self::SOURCE_SESSION, 1);

        $sites = [];
        foreach ([self::SOURCE, self::TARGET] as $origin) {
            $site = self::createStub(Site::class);
            $site->method('getBase')->willReturn(new Uri($origin . '/'));
            $site->method('getAllLanguages')->willReturn([]);
            $sites[] = $site;
        }
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);
        $this->subject = new TargetHandler(
            new OriginResolver($siteFinder, new BackendEntryPointResolver()),
            $this->transferStore,
            new BridgePage($this->get(LanguageServiceFactory::class)),
            $this->sessionManager,
            $this->clock,
        );
    }

    #[Test]
    public function bootstrapSetsOnlyAStrictHttpOnlyTargetNonce(): void
    {
        $claims = $this->claims();
        $code = $this->transferStore->issue('bootstrap', self::TARGET, $claims);
        $response = $this->subject->handle($this->request('bootstrap', $code, self::SOURCE));

        self::assertSame(200, $response->getStatusCode());
        $cookies = $this->cookies($response);
        self::assertCount(1, $cookies);
        $cookie = reset($cookies);
        self::assertSame('__Secure-typo3-sync-target-' . $claims['flow'], $cookie->getName());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertNull($cookie->getDomain());
        self::assertSame(self::BACKEND_PATH, $cookie->getPath());
        self::assertArrayNotHasKey(self::COOKIE_NAME, $cookies);
        $configuration = $this->configuration($response);
        self::assertSame('inspect', $configuration['step']['fields']['stage']);
        self::assertSame(self::TARGET . self::BACKEND_PATH . TargetHandler::BRIDGE_PATH, $configuration['step']['url']);
        self::assertStringNotContainsString(self::SOURCE_SESSION, (string)$response->getBody());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('strict-origin', $response->getHeaderLine('Referrer-Policy'));

        $replay = $this->subject->handle($this->request('bootstrap', $code, self::SOURCE));
        self::assertSame(403, $replay->getStatusCode());
        self::assertSame([], $replay->getHeader('Set-Cookie'));
    }

    public static function invalidBootstrapDataProvider(): iterable
    {
        yield 'wrong audience' => ['bootstrap', self::SOURCE, self::SOURCE, []];
        yield 'wrong purpose' => ['apply', self::TARGET, self::SOURCE, []];
        yield 'wrong request origin' => ['bootstrap', self::TARGET, 'https://attacker.example', []];
        yield 'untrusted return URL' => ['bootstrap', self::TARGET, self::SOURCE, ['returnUrl' => 'https://attacker.example/']];
        yield 'different backend path' => ['bootstrap', self::TARGET, self::SOURCE, ['targetBase' => self::TARGET . '/other/']];
        yield 'expired flow deadline' => ['bootstrap', self::TARGET, self::SOURCE, ['deadline' => 1793088000]];
    }

    #[Test]
    #[DataProvider('invalidBootstrapDataProvider')]
    public function bootstrapRejectsWrongDomainPurposeOriginAndClaims(string $purpose, string $audience, string $requestOrigin, array $overrides): void
    {
        $code = $this->transferStore->issue($purpose, $audience, array_replace($this->claims(), $overrides));

        $response = $this->subject->handle($this->request('bootstrap', $code, $requestOrigin));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    #[Test]
    public function missingStrictNonceRequiresNavigationAndPreservesExistingCookies(): void
    {
        $state = $this->bootstrap();
        $existing = $this->storeSession('existing-other-user-session', 2);
        $cookie = $this->sessionCookie($existing);

        $response = $this->subject->handle($this->request('inspect', $state['code'], self::TARGET, [self::COOKIE_NAME => $cookie]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['navigationRequired' => true], $this->json($response));
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame(2, $this->storedSession('existing-other-user-session')->getUserId());
        self::assertNull($this->transferStore->inspect($state['code'], 'inspect', self::TARGET));
    }

    #[Test]
    public function incorrectTargetNonceCannotAdvanceTheTransfer(): void
    {
        $state = $this->bootstrap();

        $response = $this->subject->handle($this->request('inspect', $state['code'], self::TARGET, [$state['cookie']->getName() => 'wrong-browser-secret']));

        self::assertSame(['navigationRequired' => true], $this->json($response));
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    #[Test]
    public function anotherAuthenticatedUserIsSkippedWithoutChangingTheSession(): void
    {
        $state = $this->bootstrap();
        $existing = $this->storeSession('existing-other-user-session', 2);
        $cookies = $this->nonceCookies($state) + [self::COOKIE_NAME => $this->sessionCookie($existing)];

        $response = $this->subject->handle($this->request('inspect', $state['code'], self::TARGET, $cookies));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame('skipped', $this->receiptClaims($response)['outcome']);
        self::assertSame(2, $this->storedSession('existing-other-user-session')->getUserId());
        self::assertSame(1, $this->storedSession(self::SOURCE_SESSION)->getUserId());
    }

    #[Test]
    public function anExistingSessionOfTheSameUserAdvancesToSourceConfirmation(): void
    {
        $state = $this->bootstrap();
        $existing = $this->storeSession('existing-same-user-session', 1);
        $cookies = $this->nonceCookies($state) + [self::COOKIE_NAME => $this->sessionCookie($existing)];

        $response = $this->subject->handle($this->request('inspect', $state['code'], self::TARGET, $cookies));

        $next = $this->json($response)['next'];
        self::assertSame(self::SOURCE . self::BACKEND_PATH . TargetHandler::BRIDGE_PATH, $next['url']);
        self::assertSame('source', $next['fields']['stage']);
        $claims = $this->transferStore->inspect($next['fields']['code'], 'confirm', self::SOURCE);
        self::assertIsArray($claims);
        self::assertSame(self::SOURCE_SESSION, $claims['sid']);
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame(1, $this->storedSession('existing-same-user-session')->getUserId());
    }

    #[Test]
    public function applyFirstLoadsATargetDocumentWithoutChangingTheBackendCookie(): void
    {
        $state = $this->bootstrap();
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);

        $response = $this->subject->handle($this->request('apply', $code, self::SOURCE));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
        $configuration = $this->configuration($response);
        self::assertSame(self::TARGET . self::BACKEND_PATH . TargetHandler::BRIDGE_PATH, $configuration['step']['url']);
        self::assertSame(['stage' => 'commit', 'code' => $code], $configuration['step']['fields']);
        self::assertSame($state['claims'], $this->transferStore->inspect($code, 'apply', self::TARGET));
    }

    #[Test]
    public function commitWithoutTheTargetBrowserNonceCannotSetASession(): void
    {
        $state = $this->bootstrap();
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);

        $response = $this->subject->handle($this->request('commit', $code, self::TARGET));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame($state['claims'], $this->transferStore->inspect($code, 'apply', self::TARGET));
    }

    #[Test]
    public function sameUserReceivesTheSameInternalSessionInATargetScopedCookie(): void
    {
        $state = $this->bootstrap();
        $existing = $this->storeSession('existing-same-user-session', 1);
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);
        $cookies = $this->nonceCookies($state) + [self::COOKIE_NAME => $this->sessionCookie($existing)];

        $response = $this->subject->handle($this->request('commit', $code, self::TARGET, $cookies));

        self::assertSame(200, $response->getStatusCode());
        $newCookie = $this->cookies($response)[self::COOKIE_NAME];
        self::assertSame(Cookie::SAMESITE_STRICT, $newCookie->getSameSite());
        self::assertTrue($newCookie->isSecure());
        self::assertTrue($newCookie->isHttpOnly());
        self::assertNull($newCookie->getDomain());
        self::assertSame('/installation/', $newCookie->getPath());
        self::assertSame(self::SOURCE_SESSION, UserSession::resolveIdentifierFromJwt($newCookie->getValue(), $this->scope(self::TARGET)));
        self::assertNull(UserSession::resolveIdentifierFromJwt($newCookie->getValue(), $this->scope(self::SOURCE)));
        self::assertNotSame(self::SOURCE_SESSION, $newCookie->getValue());
        self::assertSame(1, $this->storedSession('existing-same-user-session')->getUserId());
        self::assertSame(1, $this->storedSession(self::SOURCE_SESSION)->getUserId());
        self::assertSame('verify', $this->json($response)['next']['fields']['stage']);

        $replay = $this->subject->handle($this->request('commit', $code, self::TARGET, $cookies));
        self::assertSame(403, $replay->getStatusCode());
        self::assertSame([], $replay->getHeader('Set-Cookie'));
    }

    #[Test]
    public function anotherUserLoggingInBeforeCommitIsPreserved(): void
    {
        $state = $this->bootstrap();
        $inspectResponse = $this->subject->handle($this->request('inspect', $state['code'], self::TARGET, $this->nonceCookies($state)));
        self::assertSame('source', $this->json($inspectResponse)['next']['fields']['stage']);
        $applyCode = $this->transferStore->issue('apply', self::TARGET, $state['claims']);
        $otherUser = $this->storeSession('late-other-user-session', 2);
        $cookies = $this->nonceCookies($state) + [self::COOKIE_NAME => $this->sessionCookie($otherUser)];

        $response = $this->subject->handle($this->request('commit', $applyCode, self::TARGET, $cookies));

        self::assertSame('skipped', $this->receiptClaims($response)['outcome']);
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame(2, $this->storedSession('late-other-user-session')->getUserId());
    }

    public static function invalidSourceDataProvider(): iterable
    {
        yield 'logged out' => ['logged-out'];
        yield 'rotated' => ['rotated'];
        yield 'expired' => ['expired'];
        yield 'changed user' => ['changed-user'];
    }

    #[Test]
    #[DataProvider('invalidSourceDataProvider')]
    public function aRevokedRotatedExpiredOrChangedSourceCannotBeApplied(string $change): void
    {
        $state = $this->bootstrap();
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);
        $backend = $this->sessionManager->getSessionBackend('BE');
        if ($change === 'logged-out') {
            $backend->remove(self::SOURCE_SESSION);
        } elseif ($change === 'rotated') {
            $this->userSessionManager()->regenerateSession(self::SOURCE_SESSION);
        } elseif ($change === 'expired') {
            $GLOBALS['EXEC_TIME'] = $this->timestamp - 301;
            $backend->update(self::SOURCE_SESSION, []);
            $GLOBALS['EXEC_TIME'] = $this->timestamp;
        } else {
            $backend->update(self::SOURCE_SESSION, ['ses_userid' => 2]);
        }

        $response = $this->subject->handle($this->request('commit', $code, self::TARGET, $this->nonceCookies($state)));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public static function cancelledFlowDataProvider(): iterable
    {
        yield 'cancelled before commit' => ['commit', null];
        yield 'superseded before commit' => ['commit', ['flow' => 'another-flow']];
        yield 'cancelled before verification' => ['verify', null];
        yield 'superseded before verification' => ['verify', ['flow' => 'another-flow']];
    }

    #[Test]
    #[DataProvider('cancelledFlowDataProvider')]
    public function cancelledOrSupersededFlowsCannotUseTheirDerivedCodes(string $stage, ?array $active): void
    {
        $state = $this->bootstrap();
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);
        $cookies = $this->nonceCookies($state);
        if ($stage === 'verify') {
            $commit = $this->subject->handle($this->request('commit', $code, self::TARGET, $cookies));
            $code = $this->json($commit)['next']['fields']['code'];
            $cookies[self::COOKIE_NAME] = $this->cookies($commit)[self::COOKIE_NAME]->getValue();
        }
        $sessionData = $this->storedSession(self::SOURCE_SESSION)->getData();
        $sessionData['backend.sessionSynchronization']['active'] = $active;
        $this->sessionManager->getSessionBackend('BE')->update(self::SOURCE_SESSION, ['ses_data' => serialize($sessionData)]);

        $response = $this->subject->handle($this->request($stage, $code, self::TARGET, $cookies));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    #[Test]
    public function aDifferentSourceSessionFingerprintCannotBeApplied(): void
    {
        $state = $this->bootstrap();
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);
        $sessionData = $this->storedSession(self::SOURCE_SESSION)->getData();
        $sessionData['backend.sessionSynchronization']['fingerprint'] = hash('sha256', 'another-session');
        $this->sessionManager->getSessionBackend('BE')->update(self::SOURCE_SESSION, ['ses_data' => serialize($sessionData)]);

        $response = $this->subject->handle($this->request('commit', $code, self::TARGET, $this->nonceCookies($state)));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    #[Test]
    public function verificationFailsWhenTheBrowserDidNotAcceptTheNewCookie(): void
    {
        $state = $this->bootstrap();
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);
        $commit = $this->subject->handle($this->request('commit', $code, self::TARGET, $this->nonceCookies($state)));
        $verifyCode = $this->json($commit)['next']['fields']['code'];

        $response = $this->subject->handle($this->request('verify', $verifyCode, self::TARGET, $this->nonceCookies($state)));

        self::assertSame('failed', $this->receiptClaims($response)['outcome']);
        self::assertArrayNotHasKey(self::COOKIE_NAME, $this->cookies($response));
    }

    #[Test]
    public function verificationConfirmsTheCookieAndRevokesTheTargetNonce(): void
    {
        $state = $this->bootstrap();
        $code = $this->transferStore->issue('apply', self::TARGET, $state['claims']);
        $commit = $this->subject->handle($this->request('commit', $code, self::TARGET, $this->nonceCookies($state)));
        $newCookie = $this->cookies($commit)[self::COOKIE_NAME];
        $verifyCode = $this->json($commit)['next']['fields']['code'];
        $cookies = $this->nonceCookies($state) + [self::COOKIE_NAME => $newCookie->getValue()];

        $response = $this->subject->handle($this->request('verify', $verifyCode, self::TARGET, $cookies));

        self::assertSame('synchronized', $this->receiptClaims($response)['outcome']);
        $clearedNonce = $this->cookies($response)[$state['cookie']->getName()];
        self::assertTrue($clearedNonce->isCleared());
        self::assertArrayNotHasKey(self::COOKIE_NAME, $this->cookies($response));
        $replay = $this->subject->handle($this->request('verify', $verifyCode, self::TARGET, $cookies));
        self::assertSame(403, $replay->getStatusCode());
        self::assertSame([], $replay->getHeader('Set-Cookie'));
    }

    private function bootstrap(): array
    {
        $claims = $this->claims();
        $code = $this->transferStore->issue('bootstrap', self::TARGET, $claims);
        $response = $this->subject->handle($this->request('bootstrap', $code, self::SOURCE));
        self::assertSame(200, $response->getStatusCode());
        $configuration = $this->configuration($response);
        $inspectCode = $configuration['step']['fields']['code'];
        $targetClaims = $this->transferStore->inspect($inspectCode, 'inspect', self::TARGET);
        self::assertIsArray($targetClaims);

        return [
            'code' => $inspectCode,
            'claims' => $targetClaims,
            'cookie' => $this->cookies($response)[$targetClaims['targetCookie']],
        ];
    }

    private function claims(): array
    {
        return [
            'flow' => str_repeat('a', 32),
            'source' => self::SOURCE,
            'target' => self::TARGET,
            'sourceBase' => self::SOURCE . self::BACKEND_PATH,
            'targetBase' => self::TARGET . self::BACKEND_PATH,
            'sid' => self::SOURCE_SESSION,
            'userId' => 1,
            'sourceCookie' => '__Secure-typo3-sync-source-' . str_repeat('a', 32),
            'sourceHash' => hash('sha256', 'source-browser-secret'),
            'returnUrl' => self::SOURCE . self::BACKEND_PATH,
            'mode' => 'navigation',
            'deadline' => $this->timestamp + 180,
        ];
    }

    private function request(string $stage, string $code, string $requestOrigin, array $cookies = []): ServerRequest
    {
        $normalizedParams = self::createStub(NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn(self::TARGET);
        $normalizedParams->method('getRequestHostOnly')->willReturn((string)parse_url(self::TARGET, PHP_URL_HOST));
        $normalizedParams->method('getSitePath')->willReturn('/installation/');
        $normalizedParams->method('isHttps')->willReturn(true);

        return new ServerRequest(self::TARGET . self::BACKEND_PATH . TargetHandler::BRIDGE_PATH, 'POST')
            ->withHeader('Origin', $requestOrigin)
            ->withAttribute('normalizedParams', $normalizedParams)
            ->withParsedBody(['stage' => $stage, 'code' => $code])
            ->withCookieParams($cookies);
    }

    private function storeSession(string $identifier, int $userId): UserSession
    {
        $data = ['moduleData' => ['sorting' => 'title']];
        if ($identifier === self::SOURCE_SESSION) {
            $claims = $this->claims();
            $data['backend.sessionSynchronization'] = [
                'fingerprint' => hash('sha256', self::SOURCE_SESSION),
                'owner' => self::SOURCE,
                'active' => [
                    'flow' => $claims['flow'],
                    'origin' => self::TARGET,
                    'expires' => $claims['deadline'],
                    'mode' => $claims['mode'],
                ],
            ];
        }
        $record = $this->sessionManager->getSessionBackend('BE')->set($identifier, [
            'ses_userid' => $userId,
            'ses_iplock' => IpLocker::DISABLED_LOCK_VALUE,
            'ses_data' => serialize($data),
        ]);

        return UserSession::createFromRecord($identifier, $record);
    }

    private function storedSession(string $identifier): UserSession
    {
        return $this->userSessionManager()->createSessionFromStorage($identifier);
    }

    private function userSessionManager(): UserSessionManager
    {
        return UserSessionManager::create('BE', sessionManager: $this->sessionManager, clock: $this->clock);
    }

    private function scope(string $origin): CookieScope
    {
        return new CookieScope((string)parse_url($origin, PHP_URL_HOST), true, '/installation/');
    }

    private function sessionCookie(UserSession $session): string
    {
        return $session->getJwt($this->scope(self::TARGET));
    }

    private function nonceCookies(array $state): array
    {
        return [$state['cookie']->getName() => $state['cookie']->getValue()];
    }

    private function cookies(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = Cookie::fromString($header);
            $cookies[$cookie->getName()] = $cookie;
        }
        return $cookies;
    }

    private function configuration(ResponseInterface $response): array
    {
        self::assertSame(1, preg_match('/const configuration = (.+);\nconst allowedOrigins/', (string)$response->getBody(), $matches));
        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function receiptClaims(ResponseInterface $response): array
    {
        $next = $this->json($response)['next'];
        self::assertSame(self::SOURCE . self::BACKEND_PATH . TargetHandler::BRIDGE_PATH, $next['url']);
        self::assertSame('receipt', $next['fields']['stage']);
        $claims = $this->transferStore->inspect($next['fields']['code'], 'receipt', self::SOURCE);
        self::assertIsArray($claims);
        return $claims;
    }
}
