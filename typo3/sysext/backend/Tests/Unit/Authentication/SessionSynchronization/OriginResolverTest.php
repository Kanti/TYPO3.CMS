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

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\OriginResolver;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class OriginResolverTest extends UnitTestCase
{
    public static function originsDataProvider(): iterable
    {
        yield 'ordinary HTTPS origin' => ['https://example.org', 'https://example.org'];
        yield 'default port and case' => ['HTTPS://EXAMPLE.ORG:443/path', 'https://example.org'];
        yield 'distinct nondefault port' => ['https://example.org:8443/path', 'https://example.org:8443'];
        yield 'internationalized domain' => ['https://bücher.example/path', 'https://xn--bcher-kva.example'];
        yield 'browser compatible nontransitional IDNA' => ['https://faß.example/', 'https://xn--fa-hia.example'];
        yield 'IPv4' => ['https://127.0.0.1:8443/', 'https://127.0.0.1:8443'];
        yield 'IPv6' => ['https://[2001:0DB8:0:0:0:0:0:1]:443/', 'https://[2001:db8::1]'];
        yield 'IPv6 loopback' => ['https://[0:0:0:0:0:0:0:1]:8443/', 'https://[::1]:8443'];
        yield 'IPv4 mapped IPv6' => ['https://[::ffff:192.0.2.1]/', 'https://[::ffff:c000:201]'];
        yield 'query cannot change origin' => ['https://example.org/path?target=https://other.example', 'https://example.org'];
        yield 'distinct subdomain' => ['https://example.org.attacker.example/', 'https://example.org.attacker.example'];
        yield 'insecure HTTP' => ['http://example.org', null];
        yield 'websocket' => ['wss://example.org', null];
        yield 'relative URL' => ['/example.org', null];
        yield 'scheme relative URL' => ['//example.org', null];
        yield 'credentials' => ['https://user:password@example.org/', null];
        yield 'empty credentials' => ['https://@example.org/', null];
        yield 'fragment' => ['https://example.org/#state', null];
        yield 'empty fragment' => ['https://example.org/#', null];
        yield 'backslash parsing ambiguity' => ['https://example.org\\@attacker.example/', null];
        yield 'space' => ['https://example.org /', null];
        yield 'line break' => ["https://example.org\n/", null];
        yield 'percent encoded host' => ['https://%65xample.org/', null];
        yield 'invalid DNS label' => ['https://-example.org/', null];
        yield 'zero port' => ['https://example.org:0/', null];
        yield 'invalid port' => ['https://example.org:65536/', null];
        yield 'abbreviated IPv4' => ['https://127.1/', null];
        yield 'octal IPv4' => ['https://0177.0.0.1/', null];
        yield 'hexadecimal IPv4' => ['https://0x7f000001/', null];
        yield 'integer IPv4' => ['https://2130706433/', null];
        yield 'IPv6 zone' => ['https://[fe80::1%25eth0]/', null];
    }

    #[Test]
    #[DataProvider('originsDataProvider')]
    public function canonicalOriginRejectsAmbiguousOrInsecureOrigins(string $url, ?string $expected): void
    {
        $subject = new OriginResolver(self::createStub(SiteFinder::class), new BackendEntryPointResolver());

        self::assertSame($expected, $subject->canonicalOrigin($url));
    }

    #[Test]
    public function canonicalOriginAcceptsPsrUri(): void
    {
        $subject = new OriginResolver(self::createStub(SiteFinder::class), new BackendEntryPointResolver());

        self::assertSame('https://example.org', $subject->canonicalOrigin(new Uri('https://example.org:443/backend')));
    }

    #[Test]
    public function resolveIncludesCurrentOriginAndDisabledLanguagesWithoutUsingLanguagePathsForBackend(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';
        $normalizedParams = self::createStub(NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn('https://admin.example.org');
        $normalizedParams->method('getSitePath')->willReturn('/installation/');
        $request = new ServerRequest('https://untrusted.example/ignored')
            ->withAttribute('normalizedParams', $normalizedParams);

        $language = $this->createMock(SiteLanguage::class);
        $language->method('getBase')->willReturn(new Uri('https://disabled.example.org/de/'));
        $language->expects($this->never())->method('enabled');
        $duplicateLanguage = self::createStub(SiteLanguage::class);
        $duplicateLanguage->method('getBase')->willReturn(new Uri('https://SITE.example.org:443/en/'));
        $relativeLanguage = self::createStub(SiteLanguage::class);
        $relativeLanguage->method('getBase')->willReturn(new Uri('/fr/'));
        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://site.example.org/content/'));
        $site->method('getAllLanguages')->willReturn([$language, $duplicateLanguage, $relativeLanguage]);
        $site->expects($this->never())->method('getLanguages');
        $site->expects($this->never())->method('getConfiguration');
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);
        $subject = new OriginResolver($siteFinder, new BackendEntryPointResolver());

        self::assertSame([
            'https://admin.example.org' => 'https://admin.example.org/installation/admin/',
            'https://site.example.org' => 'https://site.example.org/installation/admin/',
            'https://disabled.example.org' => 'https://disabled.example.org/installation/admin/',
        ], $subject->resolve($request));
    }

    #[Test]
    public function resolveUsesEffectiveBasesAndSkipsUnsafeConfiguredTargets(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/typo3';
        $normalizedParams = self::createStub(NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn('https://current.example.org');
        $normalizedParams->method('getSitePath')->willReturn('/');
        $request = new ServerRequest()->withAttribute('normalizedParams', $normalizedParams);
        $languages = [];
        foreach (['http://insecure.example.org/', 'https://user@credentials.example.org/', 'https://fragment.example.org/#state', 'https://query.example.org/?redirect=other', '//relative.example.org/de/'] as $base) {
            $language = self::createStub(SiteLanguage::class);
            $language->method('getBase')->willReturn(new Uri($base));
            $languages[] = $language;
        }
        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://effective.example.org/'));
        $site->method('getAllLanguages')->willReturn($languages);
        $site->expects($this->never())->method('getConfiguration');
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);
        $subject = new OriginResolver($siteFinder, new BackendEntryPointResolver());

        self::assertSame([
            'https://current.example.org' => 'https://current.example.org/typo3/',
            'https://effective.example.org' => 'https://effective.example.org/typo3/',
            'https://relative.example.org' => 'https://relative.example.org/typo3/',
        ], $subject->resolve($request));
    }

    #[Test]
    public function resolveRequiresNormalizedRequestContext(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects($this->never())->method('getAllSites');
        $subject = new OriginResolver($siteFinder, new BackendEntryPointResolver());

        self::assertSame([], $subject->resolve(new ServerRequest('https://untrusted.example/')));
    }

    #[Test]
    public function resolveDoesNotStartFromAnInsecureOrigin(): void
    {
        $normalizedParams = self::createStub(NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn('http://current.example.org');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects($this->never())->method('getAllSites');
        $subject = new OriginResolver($siteFinder, new BackendEntryPointResolver());

        self::assertSame([], $subject->resolve(new ServerRequest()->withAttribute('normalizedParams', $normalizedParams)));
    }
}
