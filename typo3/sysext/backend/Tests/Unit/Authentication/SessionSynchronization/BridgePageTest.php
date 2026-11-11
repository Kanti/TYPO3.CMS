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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\BridgePage;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BridgePageTest extends UnitTestCase
{
    private const SOURCE = 'https://admin.example.org';
    private const TARGET = 'https://editor.example.com:8443';

    #[Test]
    public function renderRestrictsThePolicyToTheTransactionOriginsAndANonce(): void
    {
        $response = $this->createSubject()->render(
            ['url' => self::TARGET . '/installation/admin/session/synchronize', 'fields' => ['code' => 'opaque-code']],
            self::SOURCE,
            'flow',
            [self::TARGET, self::SOURCE]
        );
        $directives = [];
        foreach (explode(';', $response->getHeaderLine('Content-Security-Policy')) as $directive) {
            [$name, $value] = array_pad(explode(' ', trim($directive), 2), 2, '');
            $directives[$name] = $value;
        }

        self::assertSame("'none'", $directives['default-src']);
        self::assertSame("'none'", $directives['base-uri']);
        self::assertSame("'none'", $directives['object-src']);
        self::assertSame("'self'", $directives['connect-src']);
        self::assertSame(self::SOURCE . ' ' . self::TARGET, $directives['form-action']);
        self::assertSame(self::SOURCE, $directives['frame-ancestors']);
        self::assertMatchesRegularExpression("/^'nonce-([A-Za-z0-9+\/]+={0,2})'$/", $directives['script-src']);
        self::assertStringNotContainsString("'unsafe-inline'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('*', $response->getHeaderLine('Content-Security-Policy'));
        $nonce = substr($directives['script-src'], 7, -1);
        self::assertStringContainsString('<script nonce="' . $nonce . '">', (string)$response->getBody());
        self::assertFalse($response->hasHeader('X-Frame-Options'));
    }

    #[Test]
    public function renderPreventsCachingAndLimitsReferrerDisclosureWithoutChangingSessionCookies(): void
    {
        $response = $this->createSubject()->render(
            ['url' => self::SOURCE . '/admin/session/synchronize', 'fields' => ['code' => 'opaque-code']],
            self::SOURCE,
            'flow'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('strict-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString('<meta name="referrer" content="strict-origin">', (string)$response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function eachDocumentGetsANewScriptNonce(): void
    {
        $subject = $this->createSubject();
        $step = ['url' => self::SOURCE . '/admin/session/synchronize', 'fields' => ['code' => 'opaque-code']];

        $first = $subject->render($step, self::SOURCE, 'flow');
        $second = $subject->render($step, self::SOURCE, 'flow');

        self::assertNotSame($first->getHeaderLine('Content-Security-Policy'), $second->getHeaderLine('Content-Security-Policy'));
    }

    #[Test]
    public function transferDataCannotCloseTheScriptElement(): void
    {
        $code = '</script><script src="https://attacker.example/script.js">alert("&\'")</script>';
        $response = $this->createSubject()->render(
            ['url' => self::SOURCE . '/admin/session/synchronize', 'fields' => ['code' => $code]],
            self::SOURCE,
            'flow'
        );
        $body = (string)$response->getBody();

        self::assertSame(1, preg_match_all('/<script\b/i', $body));
        self::assertSame(1, preg_match_all('/<\/script\s*>/i', $body));
        self::assertStringNotContainsString($code, $body);
        self::assertStringContainsString('\\u003C', $body);
        self::assertSame(1, preg_match('/const configuration = (.+);\nconst allowedOrigins/', $body, $matches));
        $configuration = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($code, $configuration['step']['fields']['code']);
    }

    public static function invalidOriginsDataProvider(): iterable
    {
        yield 'HTTP' => ['http://admin.example.org'];
        yield 'scheme relative' => ['//admin.example.org'];
        yield 'missing host' => ['https:///admin'];
        yield 'credentials' => ['https://user:password@admin.example.org'];
        yield 'path' => ['https://admin.example.org/admin'];
        yield 'query' => ['https://admin.example.org?flow=one'];
        yield 'fragment' => ['https://admin.example.org#flow'];
        yield 'trailing slash' => ['https://admin.example.org/'];
        yield 'whitespace' => ['https://admin.example.org ; script-src https://attacker.example'];
        yield 'linebreak' => ["https://admin.example.org\n"];
    }

    #[Test]
    #[DataProvider('invalidOriginsDataProvider')]
    public function renderRejectsInvalidSourceOrigins(string $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createSubject()->render(
            ['url' => self::SOURCE . '/admin/session/synchronize', 'fields' => []],
            $origin,
            'flow'
        );
    }

    #[Test]
    #[DataProvider('invalidOriginsDataProvider')]
    public function renderRejectsInvalidAllowedOrigins(string $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createSubject()->render(
            ['url' => self::SOURCE . '/admin/session/synchronize', 'fields' => []],
            self::SOURCE,
            'flow',
            [$origin]
        );
    }

    #[Test]
    public function renderRejectsAnEndpointOutsideTheAllowedOrigins(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createSubject()->render(
            ['url' => 'https://attacker.example/admin/session/synchronize', 'fields' => []],
            self::SOURCE,
            'flow',
            [self::TARGET]
        );
    }

    #[Test]
    public function returnLinkPreservesTheSourceInstallationPathAndEscapesItsQuery(): void
    {
        $returnUrl = self::SOURCE . '/installation/admin/?module=web_layout&id=123';
        $response = $this->createSubject()->render(
            ['url' => self::TARGET . '/installation/admin/session/synchronize', 'fields' => [], 'returnUrl' => $returnUrl],
            self::SOURCE,
            'flow',
            [self::TARGET]
        );

        self::assertStringContainsString(
            '<a href="' . self::SOURCE . '/installation/admin/?module=web_layout&amp;id=123">Return to TYPO3</a>',
            (string)$response->getBody()
        );
    }

    public static function foreignReturnUrlsDataProvider(): iterable
    {
        yield 'other allowed origin' => [self::TARGET . '/admin/'];
        yield 'foreign origin' => ['https://attacker.example/admin/'];
        yield 'source lookalike' => ['https://admin.example.org.attacker.example/admin/'];
    }

    #[Test]
    #[DataProvider('foreignReturnUrlsDataProvider')]
    public function renderRejectsReturnLinksOutsideTheSourceOrigin(string $returnUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createSubject()->render(
            ['url' => self::TARGET . '/admin/session/synchronize', 'fields' => [], 'returnUrl' => $returnUrl],
            self::SOURCE,
            'flow',
            [self::TARGET]
        );
    }

    private function createSubject(): BridgePage
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('getLocale')->willReturn(new Locale('en'));
        $languageService->method('sL')->willReturnMap([
            ['backend.session_synchronization:bridge.completing', 'Completing sign-in'],
            ['backend.session_synchronization:bridge.return', 'Return to TYPO3'],
        ]);
        $languageServiceFactory = self::createStub(LanguageServiceFactory::class);
        $languageServiceFactory->method('createFromUserPreferences')->willReturn($languageService);

        return new BridgePage($languageServiceFactory);
    }
}
