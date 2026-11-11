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

namespace TYPO3\CMS\Backend\Tests\Unit\Authentication\SessionSynchronization;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\Coordinator;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\OriginResolver;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\TransferStore;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class CoordinatorTest extends UnitTestCase
{
    private const SOURCE = 'https://source.example.org';
    private const TARGET = 'https://target.example.org';
    private const NOW = 1700000000;

    private array $sessionData = [];
    private array $issued = [];
    private array $stored = [];
    private string $identifier = 'original-session';
    private int $garbageCollections = 0;
    private int $consumptions = 0;
    private int $affectedRows = 1;
    private BackendUserAuthentication&Stub $user;
    private Connection&Stub $connection;
    private ConnectionPool&Stub $connectionPool;
    private Coordinator $subject;
    private TransferStore $transferStore;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'session-synchronization-unit-test';
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@' . self::NOW));
        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn(new Uri(self::TARGET . '/content/'));
        $site->method('getAllLanguages')->willReturn([]);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);
        $this->connection = self::createStub(Connection::class);
        $this->connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->issued[] = $data;
            $this->stored[$data['identifier']] = $data;
            return 1;
        });
        $this->connection->method('select')->willReturnCallback(function (array $columns, string $table, array $criteria): Result {
            $result = self::createStub(Result::class);
            $result->method('fetchAssociative')->willReturn($this->stored[$criteria['identifier']] ?? false);
            return $result;
        });
        $this->connectionPool = self::createStub(ConnectionPool::class);
        $this->connectionPool->method('getConnectionForTable')->willReturn($this->connection);
        $operation = '';
        $expressionBuilder = self::createStub(ExpressionBuilder::class);
        $expressionBuilder->method('lte')->willReturnCallback(static function () use (&$operation): string {
            $operation = 'garbage';
            return '';
        });
        $expressionBuilder->method('gt')->willReturnCallback(static function () use (&$operation): string {
            $operation = 'consume';
            return '';
        });
        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':parameter');
        $queryBuilder->method('executeStatement')->willReturnCallback(function () use (&$operation): int {
            if ($operation === 'garbage') {
                $this->garbageCollections++;
                return 0;
            }
            $this->consumptions++;
            return $this->affectedRows;
        });
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $this->transferStore = new TransferStore($this->connectionPool, $clock, new CipherService(), new KeyFactory());
        $this->subject = new Coordinator(
            new OriginResolver($siteFinder, new BackendEntryPointResolver()),
            $this->transferStore,
            $clock,
        );
        $this->user = self::createStub(BackendUserAuthentication::class);
        $this->user->method('getUserId')->willReturn(42);
        $this->user->method('getSession')->willReturnCallback(fn(): UserSession => UserSession::createFromRecord($this->identifier, [
            'ses_userid' => 42,
            'ses_tstamp' => self::NOW,
            'ses_data' => serialize([]),
            'ses_iplock' => '[DISABLED]',
        ]));
        $this->user->method('getSessionData')->willReturnCallback(fn(string $key): mixed => $this->sessionData[$key] ?? null);
        $this->user->method('setAndSaveSessionData')->willReturnCallback(function (string $key, mixed $value): void {
            $this->sessionData[$key] = $value;
        });
    }

    #[Test]
    public function statusInitializesOneSharedStateWithoutExposingSessionIdentifier(): void
    {
        $response = $this->subject->control($this->request(['action' => 'status']), $this->user);
        self::assertSame([
            'enabled' => true,
            'pending' => true,
            'results' => [
                ['origin' => self::SOURCE, 'status' => 'synchronized'],
                ['origin' => self::TARGET, 'status' => 'pending'],
            ],
        ], $this->data($response));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString($this->identifier, (string)$response->getBody());
        self::assertSame(self::SOURCE, $this->sessionData['backend.sessionSynchronization']['owner']);
        self::assertStringNotContainsString($this->identifier, serialize($this->sessionData));
        self::assertSame(hash('sha256', $this->identifier), $this->sessionData['backend.sessionSynchronization']['fingerprint']);
    }

    #[Test]
    public function nextIssuesTargetBoundCodeAndAnIndependentProtectedSourceCookie(): void
    {
        $response = $this->subject->control($this->request(['action' => 'next', 'mode' => 'frame']), $this->user);
        $task = $this->data($response);
        self::assertSame(self::TARGET . '/installation/admin/session/synchronize/bridge', $task['url']);
        self::assertSame('bootstrap', $task['fields']['stage']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $task['fields']['code']);
        self::assertSame('bootstrap', $this->issued[0]['purpose']);
        self::assertSame(self::TARGET, $this->issued[0]['audience']);
        self::assertSame(hash('sha256', $task['fields']['code']), $this->issued[0]['identifier']);
        $claims = $this->transferStore->inspect($task['fields']['code'], 'bootstrap', self::TARGET);
        self::assertNotNull($claims);
        self::assertSame($this->identifier, $claims['sid']);
        self::assertSame(42, $claims['userId']);
        self::assertSame(self::NOW + 120, $claims['deadline']);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('secure', $cookie);
        self::assertStringContainsString('httponly', $cookie);
        self::assertStringContainsString('samesite=strict', $cookie);
        self::assertStringContainsString('path=/installation/admin/', $cookie);
        self::assertStringNotContainsString('domain=', $cookie);
        preg_match('/^[^=]+=([^;]+)/', $cookie, $matches);
        self::assertSame(hash('sha256', $matches[1]), $claims['sourceHash']);
        self::assertStringNotContainsString($matches[1], $this->issued[0]['data']);
        self::assertStringNotContainsString($this->identifier, (string)$response->getBody());
    }

    #[Test]
    public function nextDoesNotIssueParallelOrAutomaticRepeatedFlows(): void
    {
        $this->subject->control($this->request(['action' => 'next']), $this->user);
        $status = $this->data($this->subject->control($this->request(['action' => 'status']), $this->user));
        self::assertSame('pending', $status['results'][1]['status']);
        self::assertNull($this->data($this->subject->control($this->request(['action' => 'next']), $this->user)));
        self::assertCount(1, $this->issued);
        self::assertSame(1, $this->garbageCollections);
    }

    #[Test]
    public function sourceOperationsRejectGetEvenWithMatchingOrigin(): void
    {
        $request = $this->request(['action' => 'next'])->withMethod('GET');
        self::assertSame(403, $this->subject->control($request, $this->user)->getStatusCode());
        self::assertSame(403, $this->subject->confirm($request, $this->user)->getStatusCode());
        self::assertSame(403, $this->subject->receipt($request, $this->user)->getStatusCode());
        self::assertSame([], $this->issued);
    }

    #[Test]
    public function failureAllowsNavigationFallbackExactlyOnce(): void
    {
        $task = $this->data($this->subject->control($this->request(['action' => 'next']), $this->user));
        $this->subject->control($this->request(['action' => 'failed', 'flow' => $task['flow'], 'navigationRequired' => '1']), $this->user);
        self::assertNull($this->data($this->subject->control($this->request(['action' => 'next', 'mode' => 'frame']), $this->user)));
        $navigation = $this->data($this->subject->control($this->request(['action' => 'next', 'mode' => 'navigation']), $this->user));
        self::assertSame(self::TARGET, $navigation['target']);
        self::assertNotSame($task['flow'], $navigation['flow']);
        self::assertNull($this->data($this->subject->control($this->request(['action' => 'next', 'mode' => 'navigation']), $this->user)));
    }

    #[Test]
    public function expiredFlowBecomesTerminalFailureUntilExplicitRetry(): void
    {
        $this->subject->control($this->request(['action' => 'next']), $this->user);
        $this->sessionData['backend.sessionSynchronization']['active']['expires'] = self::NOW;
        $status = $this->data($this->subject->control($this->request(['action' => 'status']), $this->user));
        self::assertFalse($status['pending']);
        self::assertSame('failed', $status['results'][1]['status']);
        self::assertNull($this->data($this->subject->control($this->request(['action' => 'next']), $this->user)));
        $this->subject->control($this->request(['action' => 'retry']), $this->user);
        self::assertIsArray($this->data($this->subject->control($this->request(['action' => 'next']), $this->user)));
    }

    #[Test]
    public function rotationDiscardsOldFlowAndRestartsSynchronization(): void
    {
        $task = $this->data($this->subject->control($this->request(['action' => 'next']), $this->user));
        $this->identifier = 'rotated-session';
        $status = $this->data($this->subject->control($this->request(['action' => 'status']), $this->user));
        self::assertSame('pending', $status['results'][1]['status']);
        self::assertNull($this->sessionData['backend.sessionSynchronization']['active']);
        self::assertNotSame($task['flow'], $this->data($this->subject->control($this->request(['action' => 'next']), $this->user))['flow']);
    }

    #[Test]
    public function legacyStateIsReplacedWithoutRetainingItsPlaintextIdentifier(): void
    {
        $this->sessionData['backend.sessionSynchronization'] = [
            'identifier' => $this->identifier,
            'owner' => self::TARGET,
            'results' => [self::SOURCE => 'synchronized', self::TARGET => 'synchronized'],
            'active' => null,
        ];

        $status = $this->data($this->subject->control($this->request(['action' => 'status']), $this->user));

        self::assertTrue($status['pending']);
        self::assertSame(self::SOURCE, $this->sessionData['backend.sessionSynchronization']['owner']);
        self::assertStringNotContainsString($this->identifier, serialize($this->sessionData));
        self::assertSame(hash('sha256', $this->identifier), $this->sessionData['backend.sessionSynchronization']['fingerprint']);
    }

    #[Test]
    public function confirmationRejectsStateFromAnotherSessionGeneration(): void
    {
        [$claims, $secret] = $this->startFlow();
        $code = $this->provideTransfer($claims, 'confirm');
        $this->sessionData['backend.sessionSynchronization']['fingerprint'] = hash('sha256', 'other-session');

        $response = $this->subject->confirm($this->request(['code' => $code])->withCookieParams([$claims['sourceCookie'] => $secret]), $this->user);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->consumptions);
        self::assertCount(1, $this->issued);
    }

    #[Test]
    public function explicitRetryCancelsActiveFlowAndClearsItsSourceSecretImmediately(): void
    {
        [$claims, $secret] = $this->startFlow();
        $response = $this->subject->control($this->request(['action' => 'retry']), $this->user);
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->sessionData['backend.sessionSynchronization']['active']);
        self::assertSame('pending', $this->sessionData['backend.sessionSynchronization']['results'][self::TARGET]);
        self::assertStringContainsString($claims['sourceCookie'] . '=deleted;', $response->getHeaderLine('Set-Cookie'));
        $this->provideTransfer($claims, 'confirm', null);
        $confirmation = $this->subject->confirm($this->request(['code' => str_repeat('a', 64)])->withCookieParams([$claims['sourceCookie'] => $secret]), $this->user);
        self::assertSame(403, $confirmation->getStatusCode());
        self::assertSame(0, $this->consumptions);
        $next = $this->data($this->subject->control($this->request(['action' => 'next']), $this->user));
        self::assertNotSame($claims['flow'], $next['flow']);
    }

    #[Test]
    public function explicitNavigationRetryPromotesPendingAndFailedTargetsWithoutAnIframeAttempt(): void
    {
        $task = $this->data($this->subject->control($this->request(['action' => 'next']), $this->user));
        $this->subject->control($this->request(['action' => 'failed', 'flow' => $task['flow'], 'result' => 'failed']), $this->user);
        $this->subject->control($this->request(['action' => 'retry', 'mode' => 'navigation']), $this->user);
        self::assertSame('synchronized', $this->sessionData['backend.sessionSynchronization']['results'][self::SOURCE]);
        self::assertSame('navigation-required', $this->sessionData['backend.sessionSynchronization']['results'][self::TARGET]);
        self::assertNull($this->data($this->subject->control($this->request(['action' => 'next', 'mode' => 'frame']), $this->user)));
        self::assertSame(self::TARGET, $this->data($this->subject->control($this->request(['action' => 'next', 'mode' => 'navigation']), $this->user))['target']);
    }

    #[Test]
    public function explicitNavigationRetryPreservesSkippedOtherUserSessions(): void
    {
        $this->subject->control($this->request(['action' => 'status']), $this->user);
        $this->sessionData['backend.sessionSynchronization']['results'][self::TARGET] = 'skipped';
        $this->subject->control($this->request(['action' => 'retry', 'mode' => 'navigation']), $this->user);
        self::assertSame('skipped', $this->sessionData['backend.sessionSynchronization']['results'][self::TARGET]);
        self::assertNull($this->data($this->subject->control($this->request(['action' => 'next', 'mode' => 'navigation']), $this->user)));
    }

    public static function invalidOriginDataProvider(): iterable
    {
        yield 'missing origin' => [''];
        yield 'other origin' => [self::TARGET];
        yield 'null origin' => ['null'];
        yield 'different port' => [self::SOURCE . ':8443'];
    }

    #[Test]
    #[DataProvider('invalidOriginDataProvider')]
    public function allSourceOperationsRequireExactPostOrigin(string $origin): void
    {
        $request = $this->request(['action' => 'next'])->withHeader('Origin', $origin);
        self::assertSame(403, $this->subject->control($request, $this->user)->getStatusCode());
        self::assertSame(403, $this->subject->confirm($request, $this->user)->getStatusCode());
        self::assertSame(403, $this->subject->receipt($request, $this->user)->getStatusCode());
        self::assertSame([], $this->issued);
    }

    #[Test]
    public function sharedStateDoesNotStartAgainOnParticipatingDomain(): void
    {
        $this->subject->control($this->request(['action' => 'status']), $this->user);
        $normalizedParams = self::createStub(NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn(self::TARGET);
        $normalizedParams->method('getSitePath')->willReturn('/installation/');
        $request = $this->request(['action' => 'next'])->withAttribute('normalizedParams', $normalizedParams)->withHeader('Origin', self::TARGET);
        self::assertNull($this->data($this->subject->control($request, $this->user)));
        self::assertSame(self::SOURCE, $this->sessionData['backend.sessionSynchronization']['owner']);
    }

    #[Test]
    public function stolenConfirmationCodeCannotBeConsumedWithoutSourceBrowserSecret(): void
    {
        [$claims] = $this->startFlow();
        $code = $this->provideTransfer($claims, 'confirm');
        $response = $this->subject->confirm($this->request(['code' => $code]), $this->user);
        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->issued);
    }

    #[Test]
    public function confirmationIssuesApplyCodeOnlyAfterSourceBrowserProofAndAtomicConsumption(): void
    {
        [$claims, $secret] = $this->startFlow();
        $code = $this->provideTransfer($claims, 'confirm');
        $response = $this->subject->confirm($this->request(['code' => $code])->withCookieParams([$claims['sourceCookie'] => $secret]), $this->user);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::TARGET . '/installation/admin/session/synchronize/bridge', $this->data($response)['next']['url']);
        self::assertSame('apply', $this->data($response)['next']['fields']['stage']);
        self::assertSame('apply', $this->issued[1]['purpose']);
        self::assertSame(self::TARGET, $this->issued[1]['audience']);
        self::assertSame($claims, $this->transferStore->inspect($this->data($response)['next']['fields']['code'], 'apply', self::TARGET));
        self::assertSame(1, $this->consumptions);
    }

    #[Test]
    public function lostAtomicConsumptionRaceDoesNotIssueAnotherApplyCode(): void
    {
        [$claims, $secret] = $this->startFlow();
        $code = $this->provideTransfer($claims, 'confirm', 0);
        $response = $this->subject->confirm($this->request(['code' => $code])->withCookieParams([$claims['sourceCookie'] => $secret]), $this->user);
        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->issued);
        self::assertSame(1, $this->consumptions);
    }

    public static function invalidClaimsDataProvider(): iterable
    {
        yield 'different internal session' => ['sid', 'other-session'];
        yield 'different user' => ['userId', 43];
        yield 'different source' => ['source', self::TARGET];
        yield 'unconfigured target' => ['target', 'https://attacker.example.org'];
        yield 'different source backend path' => ['sourceBase', self::SOURCE . '/other/'];
        yield 'different target backend path' => ['targetBase', self::TARGET . '/other/'];
        yield 'foreign return URL' => ['returnUrl', 'https://attacker.example.org/'];
        yield 'different flow' => ['flow', str_repeat('b', 32)];
        yield 'different mode' => ['mode', 'navigation'];
        yield 'expired deadline' => ['deadline', self::NOW];
        yield 'prolonged deadline' => ['deadline', self::NOW + 121];
        yield 'different source cookie' => ['sourceCookie', 'be_typo_user'];
        yield 'wrong source secret' => ['sourceHash', str_repeat('b', 64)];
    }

    #[Test]
    #[DataProvider('invalidClaimsDataProvider')]
    public function confirmationRejectsClaimsOutsideCurrentFlow(string $claim, mixed $value): void
    {
        [$claims, $secret] = $this->startFlow();
        $cookies = [$claims['sourceCookie'] => $secret];
        $claims[$claim] = $value;
        $code = $this->provideTransfer($claims, 'confirm', null);
        $response = $this->subject->confirm($this->request(['code' => $code])->withCookieParams($cookies), $this->user);
        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->issued);
        self::assertSame(0, $this->consumptions);
    }

    #[Test]
    public function receiptCompletesSharedStatusAndClearsSourceSecret(): void
    {
        [$claims, $secret] = $this->startFlow();
        $claims['outcome'] = 'synchronized';
        $code = $this->provideTransfer($claims, 'receipt');
        $response = $this->subject->receipt($this->request(['code' => $code])->withCookieParams([$claims['sourceCookie'] => $secret]), $this->user);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 'synchronized', 'returnUrl' => self::SOURCE . '/installation/admin/'], $this->data($response));
        self::assertSame('synchronized', $this->sessionData['backend.sessionSynchronization']['results'][self::TARGET]);
        self::assertNull($this->sessionData['backend.sessionSynchronization']['active']);
        self::assertStringContainsString($claims['sourceCookie'] . '=deleted;', $response->getHeaderLine('Set-Cookie'));
    }

    private function startFlow(): array
    {
        $response = $this->subject->control($this->request(['action' => 'next']), $this->user);
        $claims = $this->transferStore->inspect($this->data($response)['fields']['code'], 'bootstrap', self::TARGET);
        self::assertNotNull($claims);
        $claims['targetCookie'] = '__Secure-target';
        $claims['targetHash'] = str_repeat('a', 64);
        preg_match('/^[^=]+=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches);
        return [$claims, $matches[1]];
    }

    private function provideTransfer(array $claims, string $purpose, ?int $affectedRows = 1): string
    {
        $code = $this->transferStore->issue($purpose, self::SOURCE, $claims);
        array_pop($this->issued);
        $this->affectedRows = $affectedRows ?? 1;
        return $code;
    }

    private function request(array $data): ServerRequest
    {
        $normalizedParams = self::createStub(NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn(self::SOURCE);
        $normalizedParams->method('getSitePath')->willReturn('/installation/');
        return new ServerRequest(self::SOURCE . '/installation/admin/session/synchronize/control', 'POST')
            ->withAttribute('normalizedParams', $normalizedParams)
            ->withHeader('Origin', self::SOURCE)
            ->withParsedBody($data);
    }

    private function data(ResponseInterface $response): ?array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
