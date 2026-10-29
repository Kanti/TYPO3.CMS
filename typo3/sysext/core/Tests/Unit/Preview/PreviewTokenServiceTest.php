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

namespace TYPO3\CMS\Core\Tests\Unit\Preview;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use TYPO3\CMS\Core\Clock\SystemClock;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class PreviewTokenServiceTest extends UnitTestCase
{
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';

    public function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '4408d27a916d51e624b69af3554f516dbab61037a9f7b9fd6f81b4d3bedeccb6';
    }

    #[Test]
    public function signedTokenIsVerified(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $profile = $this->createProfile();
        $signed = $subject->sign($profile, 'https://site-a.com', self::USER_AGENT, $this->createUser());

        $verified = $subject->verify($signed->jwt, self::USER_AGENT);

        self::assertEquals($profile, $verified->profile);
        self::assertFalse($verified->visitor);
        self::assertSame(5, $verified->editorId);
        self::assertSame('https://site-a.com', $verified->backendOrigin);
        self::assertSame($signed->expires, $verified->expires);
        self::assertEqualsWithDelta(time() + PreviewTokenService::LIFETIME, $verified->expires, 2);
        self::assertSame(5, $verified->userId);
    }

    #[Test]
    public function tokenWithoutUserIsAVisitorToken(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $visitor = new PreviewProfile(0, false, false, 0, []);
        $jwt = $subject->sign($visitor, 'https://site-a.com', self::USER_AGENT, $this->createUser(), true)->jwt;

        $verified = $subject->verify($jwt, self::USER_AGENT);

        self::assertNull($verified->userId);
        self::assertTrue($verified->visitor);
        self::assertTrue($verified->profile->isVisitorView());
        self::assertSame(5, $verified->editorId);
    }

    #[Test]
    public function tokenOfAnEditorNamesNeitherHostsNorSites(): void
    {
        $jwt = new PreviewTokenService(new SystemClock())->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;

        $claims = $this->decodeClaims($jwt);

        self::assertArrayNotHasKey('aud', $claims);
        self::assertArrayNotHasKey('site', $claims);
        self::assertArrayNotHasKey('sites', $claims['prf']);
        self::assertArrayNotHasKey('scp', $claims);
        self::assertSame(5, $claims['sub']);
    }

    /**
     * A token without a user names the editor who opened it only to decide where it is
     * valid: on the sites this editor may preview now, as for a token of the editor.
     */
    #[Test]
    public function tokenWithoutUserNamesTheEditorWhoseRightsDecideWhereItIsValid(): void
    {
        $jwt = new PreviewTokenService(new SystemClock())->sign(new PreviewProfile(0, false, false, 0, []), 'https://site-a.com', self::USER_AGENT, $this->createUser(), true)->jwt;

        $claims = $this->decodeClaims($jwt);

        self::assertSame(5, $claims['scp']);
        self::assertArrayNotHasKey('sub', $claims);
        self::assertArrayNotHasKey('aud', $claims);
        self::assertArrayNotHasKey('site', $claims);
    }

    public static function forgedEditorClaimsDataProvider(): \Generator
    {
        yield 'visitor without editor' => [['sub' => null]];
        yield 'visitor with editor zero' => [['sub' => null, 'scp' => 0]];
        yield 'visitor with editor as string' => [['sub' => null, 'scp' => '5']];
        yield 'editor naming a second editor' => [['scp' => 7]];
    }

    #[Test]
    #[DataProvider('forgedEditorClaimsDataProvider')]
    public function tokenWhoseEditorClaimsDoNotFitItsKindIsRefused(array $changes): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $claims = $this->decodeClaims($subject->sign(new PreviewProfile(0, false, false, 0, []), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt);
        foreach ($changes as $name => $value) {
            if ($value === null) {
                unset($claims[$name]);
            } else {
                $claims[$name] = $value;
            }
        }
        $forged = JWT::encode($claims, hash_hkdf('sha256', $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], 32, PreviewTokenService::class), 'HS256');

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify($forged, self::USER_AGENT));
    }

    #[Test]
    public function tokenWithoutUserAskingForMoreThanTheVisitorViewIsRefused(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $jwt = $subject->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser(), true)->jwt;

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify($jwt, self::USER_AGENT));
    }

    #[Test]
    public function tokenWithChangedUserIsRefused(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $jwt = $subject->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        [$header, $payload, $signature] = explode('.', $jwt);
        $claims = json_decode(JWT::urlsafeB64Decode($payload), true);
        $claims['sub'] = 1;
        $tampered = $header . '.' . JWT::urlsafeB64Encode(json_encode($claims)) . '.' . $signature;

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify($tampered, self::USER_AGENT));
    }

    #[Test]
    public function tokenWithChangedByteIsRefused(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $jwt = $subject->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        [$header, $payload, $signature] = explode('.', $jwt);
        $claims = json_decode(JWT::urlsafeB64Decode($payload), true);
        $claims['prf']['ws'] = 4;
        $tampered = $header . '.' . JWT::urlsafeB64Encode(json_encode($claims)) . '.' . $signature;

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify($tampered, self::USER_AGENT));
    }

    #[Test]
    public function tokenOfAnotherTypeIsRefused(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $jwt = $subject->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[1]), true);
        $claims['typ'] = 'session';
        $signingKey = hash_hkdf('sha256', $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], 32, PreviewTokenService::class);
        $otherType = JWT::encode($claims, $signingKey, 'HS256');

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify($otherType, self::USER_AGENT));
    }

    #[Test]
    public function tokenSignedWithAnotherKeyIsRefused(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $jwt = $subject->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[1]), true);
        $signingKey = hash_hkdf('sha256', $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], 32, 'TYPO3\\CMS\\Core\\Session\\UserSession');
        $foreign = JWT::encode($claims, $signingKey, 'HS256');

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify($foreign, self::USER_AGENT));
    }

    #[Test]
    public function expiredTokenIsRefused(): void
    {
        $past = new PreviewTokenService($this->createClock(time() - PreviewTokenService::LIFETIME - 10));
        $jwt = $past->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        $subject = new PreviewTokenService(new SystemClock());

        $this->assertRejected(PreviewRejection::Expired, fn() => $subject->verify($jwt, self::USER_AGENT));
    }

    #[Test]
    public function tokenIsRefusedOnceTheClockPassedItsExpiry(): void
    {
        $jwt = new PreviewTokenService(new SystemClock())->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        $subject = new PreviewTokenService($this->createClock(time() + PreviewTokenService::LIFETIME + 1));

        $this->assertRejected(PreviewRejection::Expired, fn() => $subject->verify($jwt, self::USER_AGENT));
    }

    #[Test]
    public function tokenOfABackendWhoseClockIsUpToAMinuteAheadIsAccepted(): void
    {
        $jwt = new PreviewTokenService($this->createClock(time() + 30))->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;

        $verified = new PreviewTokenService(new SystemClock())->verify($jwt, self::USER_AGENT);

        self::assertSame(5, $verified->userId);
    }

    #[Test]
    public function tokenOfABackendWhoseClockIsMoreThanAMinuteAheadIsRefused(): void
    {
        $jwt = new PreviewTokenService($this->createClock(time() + 120))->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        $subject = new PreviewTokenService(new SystemClock());

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify($jwt, self::USER_AGENT));
    }

    #[Test]
    public function tokenIsRefusedInAnotherBrowser(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $jwt = $subject->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;

        $this->assertRejected(PreviewRejection::OtherBrowser, fn() => $subject->verify($jwt, 'curl/8.0'));
    }

    #[Test]
    public function malformedTokenIsRefused(): void
    {
        $subject = new PreviewTokenService(new SystemClock());

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->verify('no-jwt-at-all', self::USER_AGENT));
    }

    #[Test]
    public function expiredTokenIsReadForRenewal(): void
    {
        $past = new PreviewTokenService($this->createClock(time() - PreviewTokenService::LIFETIME - 10));
        $profile = $this->createProfile();
        $jwt = $past->sign($profile, 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;

        $token = new PreviewTokenService(new SystemClock())->readForRenewal($jwt, self::USER_AGENT);
        self::assertEquals($profile, $token->profile);
        self::assertSame(5, $token->userId);
    }

    #[Test]
    public function tokenIsNotReadForRenewalWhenTamperedOrInAnotherBrowser(): void
    {
        $subject = new PreviewTokenService(new SystemClock());
        $jwt = $subject->sign($this->createProfile(), 'https://site-a.com', self::USER_AGENT, $this->createUser())->jwt;
        [$header, $payload, $signature] = explode('.', $jwt);
        $claims = json_decode(JWT::urlsafeB64Decode($payload), true);
        $claims['prf']['groups'] = [1];
        $tampered = $header . '.' . JWT::urlsafeB64Encode(json_encode($claims)) . '.' . $signature;

        $this->assertRejected(PreviewRejection::Invalid, fn() => $subject->readForRenewal($tampered, self::USER_AGENT));
        $this->assertRejected(PreviewRejection::OtherBrowser, fn() => $subject->readForRenewal($jwt, 'curl/8.0'));
    }

    #[Test]
    public function tokenParameterIsReplacedOnce(): void
    {
        $uri = new Uri('https://site-b.com/team?a=1&__t3ct=old#top');

        self::assertSame('https://site-b.com/team?a=1&__t3ct=new#top', (string)PreviewTokenService::withToken($uri, 'new'));
        self::assertSame('https://site-b.com/team?a=1#top', (string)PreviewTokenService::withoutToken($uri));
    }

    public static function queryWithoutTokenDataProvider(): \Generator
    {
        $query = 'utm.source=x&id=1&id=2&flag&q=a+b&a[]=1&a[]=2&a%20b=1&sig=ab%2bcd&empty=&&last';
        yield 'token at the start' => ['https://site-b.com/p?__t3ct=jwt&' . $query . '#top', 'https://site-b.com/p?' . $query . '#top'];
        yield 'token in the middle' => ['https://site-b.com/p?utm.source=x&id=1&__t3ct=jwt&id=2&flag&q=a+b&a[]=1&a[]=2&a%20b=1&sig=ab%2bcd&empty=&&last#top', 'https://site-b.com/p?' . $query . '#top'];
        yield 'token at the end' => ['https://site-b.com/p?' . $query . '&__t3ct=jwt#top', 'https://site-b.com/p?' . $query . '#top'];
        yield 'token without value' => ['https://site-b.com/p?' . $query . '&__t3ct', 'https://site-b.com/p?' . $query];
        yield 'token with empty value' => ['https://site-b.com/p?' . $query . '&__t3ct=', 'https://site-b.com/p?' . $query];
        yield 'encoded token name' => ['https://site-b.com/p?%5F%5Ft3ct=jwt&' . $query, 'https://site-b.com/p?' . $query];
        yield 'token twice' => ['https://site-b.com/p?__t3ct=a&' . $query . '&__t3ct=b', 'https://site-b.com/p?' . $query];
        yield 'only the token' => ['https://site-b.com/p?__t3ct=jwt#top', 'https://site-b.com/p#top'];
        yield 'no token' => ['https://site-b.com/p?' . $query . '#top', 'https://site-b.com/p?' . $query . '#top'];
        yield 'no query' => ['https://site-b.com/p#top', 'https://site-b.com/p#top'];
        yield 'similar names are kept' => ['https://site-b.com/p?__t3ct_x=1&x__t3ct=2&__t3ct[]=3', 'https://site-b.com/p?__t3ct_x=1&x__t3ct=2&__t3ct[]=3'];
    }

    #[Test]
    #[DataProvider('queryWithoutTokenDataProvider')]
    public function withoutTokenRemovesOnlyTheTokenFromTheQuery(string $uri, string $expected): void
    {
        self::assertSame((string)new Uri($expected), (string)PreviewTokenService::withoutToken(new Uri($uri)));
    }

    #[Test]
    #[DataProvider('queryWithoutTokenDataProvider')]
    public function withTokenReplacesTheTokenAndKeepsTheQuery(string $uri, string $expected): void
    {
        $expectedUri = new Uri($expected);
        $expectedQuery = $expectedUri->getQuery() === '' ? '__t3ct=new' : $expectedUri->getQuery() . '&__t3ct=new';

        self::assertSame((string)$expectedUri->withQuery($expectedQuery), (string)PreviewTokenService::withToken(new Uri($uri), 'new'));
    }

    #[Test]
    public function queryKeepsItsBytes(): void
    {
        $uri = new Uri('https://site-b.com/p?utm.source=x&id=1&id=2&flag&q=a+b&sig=ab%2bcd&__t3ct=jwt#top');

        self::assertSame('utm.source=x&id=1&id=2&flag&q=a+b&sig=ab%2bcd', PreviewTokenService::withoutToken($uri)->getQuery());
        self::assertSame('utm.source=x&id=1&id=2&flag&q=a+b&sig=ab%2bcd&__t3ct=new', PreviewTokenService::withToken($uri, 'new')->getQuery());
    }

    #[Test]
    public function uriWithoutTokenIsReturnedAsItIs(): void
    {
        $uri = new Uri('https://site-b.com/p?flag&q=a+b');

        self::assertSame($uri, PreviewTokenService::withoutToken($uri));
    }

    private function createProfile(): PreviewProfile
    {
        return new PreviewProfile(
            workspace: 3,
            hiddenPages: true,
            hiddenContent: false,
            date: 1796083200,
            groups: [3, 7],
        );
    }

    private function decodeClaims(string $jwt): array
    {
        return json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function createUser(): int
    {
        return 5;
    }

    private function createClock(int $timestamp): ClockInterface
    {
        return new readonly class ($timestamp) implements ClockInterface {
            public function __construct(private int $timestamp) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->timestamp);
            }
        };
    }

    private function assertRejected(PreviewRejection $expected, \Closure $verify): void
    {
        try {
            $verify();
            self::fail('Preview token was accepted');
        } catch (PreviewTokenRejectedException $exception) {
            self::assertSame($expected, $exception->rejection);
        }
    }
}
