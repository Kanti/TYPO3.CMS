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
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\TransferStore;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\CipherValue;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TransferStoreTest extends FunctionalTestCase
{
    private TransferStore $subject;
    private Connection $connection;
    private int $timestamp = 1793088000;

    protected function setUp(): void
    {
        parent::setUp();
        $connectionPool = $this->get(ConnectionPool::class);
        $this->connection = $connectionPool->getConnectionForTable('be_session_transfer');
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(fn(): \DateTimeImmutable => new \DateTimeImmutable('@' . $this->timestamp));
        $this->subject = new TransferStore($connectionPool, $clock, new CipherService(), new KeyFactory());
    }

    #[Test]
    public function storesOnlyTheHashOfAnOpaqueCode(): void
    {
        $payload = ['session' => 'sensitive-session-id', 'browser' => 'browser-binding'];
        $code = $this->subject->issue('login-sync', 'https://target.example', $payload);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $code);
        self::assertStringNotContainsString('sensitive-session-id', $code);
        $record = $this->connection->select(['*'], 'be_session_transfer', [])->fetchAssociative();
        self::assertIsArray($record);
        self::assertSame(hash('sha256', $code), $record['identifier']);
        self::assertNotSame($code, $record['identifier']);
        self::assertSame($this->timestamp + 60, (int)$record['expires']);
        self::assertSame($payload, $this->subject->inspect($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function encryptsTheEntireTransferPayloadInStorage(): void
    {
        $payload = ['sid' => 'sensitive-session-id', 'sourceHash' => 'sensitive-browser-binding'];
        $code = $this->subject->issue('bootstrap', 'https://target.example', $payload);
        $record = $this->connection->select(['*'], 'be_session_transfer', ['identifier' => hash('sha256', $code)])->fetchAssociative();

        self::assertIsArray($record);
        self::assertStringNotContainsString($payload['sid'], json_encode($record, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($payload['sourceHash'], json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame($payload, $this->subject->consume($code, 'bootstrap', 'https://target.example'));
    }

    #[Test]
    public function separateCodesForTheSamePayloadAreIndependent(): void
    {
        $payload = ['session' => 'session-id'];
        $firstCode = $this->subject->issue('login-sync', 'https://target.example', $payload);
        $secondCode = $this->subject->issue('login-sync', 'https://target.example', $payload);

        self::assertNotSame($firstCode, $secondCode);
        self::assertSame($payload, $this->subject->consume($firstCode, 'login-sync', 'https://target.example'));
        self::assertSame($payload, $this->subject->consume($secondCode, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function inspectingACodeDoesNotConsumeIt(): void
    {
        $payload = ['session' => 'session-id'];
        $code = $this->subject->issue('login-sync', 'https://target.example', $payload);

        self::assertSame($payload, $this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertSame($payload, $this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertSame($payload, $this->subject->consume($code, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function anotherStoreInstanceCannotRedeemAnAlreadyConsumedCode(): void
    {
        $payload = ['session' => 'session-id'];
        $code = $this->subject->issue('login-sync', 'https://target.example', $payload);
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@' . $this->timestamp));
        $anotherStore = new TransferStore($this->get(ConnectionPool::class), $clock, new CipherService(), new KeyFactory());

        self::assertSame($payload, $this->subject->consume($code, 'login-sync', 'https://target.example'));
        self::assertNull($anotherStore->consume($code, 'login-sync', 'https://target.example'));
    }

    public static function invalidAudienceDataProvider(): iterable
    {
        yield 'another domain' => ['https://other.example'];
        yield 'subdomain' => ['https://sub.target.example'];
        yield 'another scheme' => ['http://target.example'];
        yield 'another port' => ['https://target.example:8443'];
        yield 'domain suffix' => ['https://target.example.attacker.example'];
        yield 'case-sensitive audience' => ['https://TARGET.example'];
    }

    #[Test]
    #[DataProvider('invalidAudienceDataProvider')]
    public function aCodeCannotBeUsedForAnotherAudience(string $audience): void
    {
        $payload = ['session' => 'session-id'];
        $code = $this->subject->issue('login-sync', 'https://target.example', $payload);

        self::assertNull($this->subject->inspect($code, 'login-sync', $audience));
        self::assertNull($this->subject->consume($code, 'login-sync', $audience));
        self::assertSame($payload, $this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function aCodeCannotBeUsedForAnotherPurpose(): void
    {
        $payload = ['session' => 'session-id'];
        $code = $this->subject->issue('login-sync', 'https://target.example', $payload);

        self::assertNull($this->subject->inspect($code, 'different-purpose', 'https://target.example'));
        self::assertNull($this->subject->consume($code, 'different-purpose', 'https://target.example'));
        self::assertSame($payload, $this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function codeExpiresAtTheExactExpirationTime(): void
    {
        $payload = ['session' => 'session-id'];
        $code = $this->subject->issue('login-sync', 'https://target.example', $payload);
        $this->timestamp += 59;
        self::assertSame($payload, $this->subject->inspect($code, 'login-sync', 'https://target.example'));

        ++$this->timestamp;
        self::assertNull($this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    public static function malformedCodeDataProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 63)];
        yield 'long' => [str_repeat('a', 65)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'non-hexadecimal' => [str_repeat('g', 64)];
        yield 'trailing newline' => [str_repeat('a', 64) . "\n"];
        yield 'SQL syntax' => ["' OR '1'='1"];
    }

    #[Test]
    #[DataProvider('malformedCodeDataProvider')]
    public function malformedCodesAreRejected(string $code): void
    {
        self::assertNull($this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    public static function malformedPayloadDataProvider(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'JSON null' => ['null'];
        yield 'JSON string' => ['"session-id"'];
        yield 'JSON number' => ['42'];
        yield 'legacy plaintext payload' => ['{"sid":"session-id"}'];
        yield 'invalid nonce type' => [StringUtility::base64urlEncode('{"nonce":[],"cipher":"YWJj"}')];
        yield 'invalid cipher type' => [StringUtility::base64urlEncode('{"nonce":"YWJj","cipher":[]}')];
    }

    #[Test]
    #[DataProvider('malformedPayloadDataProvider')]
    public function malformedStoredPayloadsAreRejected(string $payload): void
    {
        $code = $this->subject->issue('login-sync', 'https://target.example', ['session' => 'session-id']);
        $this->connection->update('be_session_transfer', ['data' => $payload], ['identifier' => hash('sha256', $code)]);

        self::assertNull($this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function modifiedCiphertextIsRejected(): void
    {
        $code = $this->subject->issue('login-sync', 'https://target.example', ['sid' => 'session-id']);
        $record = $this->connection->select(['data'], 'be_session_transfer', ['identifier' => hash('sha256', $code)])->fetchAssociative();
        self::assertIsArray($record);
        $cipherValue = CipherValue::fromSerialized($record['data']);
        $cipher = $cipherValue->cipher;
        $cipher[0] = chr(ord($cipher[0]) ^ 1);
        $modified = new CipherValue($cipherValue->nonce, $cipher);
        $this->connection->update('be_session_transfer', ['data' => $modified->encode()], ['identifier' => hash('sha256', $code)]);

        self::assertNull($this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function ciphertextCannotBeMovedToAnotherCode(): void
    {
        $firstCode = $this->subject->issue('login-sync', 'https://target.example', ['sid' => 'first-session-id']);
        $secondCode = $this->subject->issue('login-sync', 'https://target.example', ['sid' => 'second-session-id']);
        $record = $this->connection->select(['data'], 'be_session_transfer', ['identifier' => hash('sha256', $firstCode)])->fetchAssociative();
        self::assertIsArray($record);
        $this->connection->update('be_session_transfer', ['data' => $record['data']], ['identifier' => hash('sha256', $secondCode)]);

        self::assertNull($this->subject->inspect($secondCode, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->consume($secondCode, 'login-sync', 'https://target.example'));
        self::assertSame(['sid' => 'first-session-id'], $this->subject->consume($firstCode, 'login-sync', 'https://target.example'));
    }

    public static function modifiedMetadataDataProvider(): iterable
    {
        yield 'purpose' => ['purpose', 'different-purpose'];
        yield 'audience' => ['audience', 'https://other.example'];
        yield 'expiration' => ['expires', '1793088120'];
    }

    #[Test]
    #[DataProvider('modifiedMetadataDataProvider')]
    public function modifiedMetadataIsRejected(string $field, string $value): void
    {
        $code = $this->subject->issue('login-sync', 'https://target.example', ['sid' => 'session-id']);
        $this->connection->update('be_session_transfer', [$field => $value], ['identifier' => hash('sha256', $code)]);
        $purpose = $field === 'purpose' ? $value : 'login-sync';
        $audience = $field === 'audience' ? $value : 'https://target.example';

        self::assertNull($this->subject->inspect($code, $purpose, $audience));
        self::assertNull($this->subject->consume($code, $purpose, $audience));
    }

    #[Test]
    public function anotherEncryptionKeyCannotDecryptAPayload(): void
    {
        $payload = ['sid' => 'session-id'];
        $code = $this->subject->issue('login-sync', 'https://target.example', $payload);
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'];
        try {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'different-installation-encryption-key';
            self::assertNull($this->subject->inspect($code, 'login-sync', 'https://target.example'));
            self::assertNull($this->subject->consume($code, 'login-sync', 'https://target.example'));
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $encryptionKey;
        }

        self::assertSame($payload, $this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function removingACodeRevokesIt(): void
    {
        $code = $this->subject->issue('login-sync', 'https://target.example', ['session' => 'session-id']);
        $this->subject->remove($code);

        self::assertNull($this->subject->inspect($code, 'login-sync', 'https://target.example'));
        self::assertNull($this->subject->consume($code, 'login-sync', 'https://target.example'));
    }

    #[Test]
    public function garbageCollectionPreservesUnexpiredCodes(): void
    {
        $expiredCode = $this->subject->issue('login-sync', 'https://expired.example', ['session' => 'expired']);
        $validPayload = ['session' => 'valid'];
        $validCode = $this->subject->issue('login-sync', 'https://valid.example', $validPayload, 120);
        $this->timestamp += 60;

        $this->subject->collectGarbage();

        self::assertSame(0, $this->connection->count('*', 'be_session_transfer', ['identifier' => hash('sha256', $expiredCode)]));
        self::assertSame($validPayload, $this->subject->consume($validCode, 'login-sync', 'https://valid.example'));
    }

    public static function invalidLifetimeDataProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'zero' => [0];
        yield 'too long' => [301];
    }

    #[Test]
    #[DataProvider('invalidLifetimeDataProvider')]
    public function lifetimeMustBePositiveAndAtMostFiveMinutes(int $lifetime): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->issue('login-sync', 'https://target.example', [], $lifetime);
    }
}
