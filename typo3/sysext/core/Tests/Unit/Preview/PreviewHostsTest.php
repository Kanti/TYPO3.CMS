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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Preview\PreviewHosts;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PreviewHostsTest extends UnitTestCase
{
    private function createScope(): PreviewHosts
    {
        return new PreviewHosts([
            ['site' => 'main', 'host' => 'example.com', 'origin' => 'https://example.com'],
            ['site' => 'main', 'host' => 'example.de', 'origin' => 'https://example.de'],
            ['site' => 'shop', 'host' => 'example.com', 'origin' => 'https://example.com'],
            ['site' => 'intranet', 'host' => 'intranet.example:8443', 'origin' => 'https://intranet.example:8443'],
        ]);
    }

    #[Test]
    public function sitesAreKnownAndHostsAndOriginsAreListedOnce(): void
    {
        $scope = $this->createScope();

        self::assertTrue($scope->includesSite('shop'));
        self::assertFalse($scope->includesSite('blog'));
        self::assertSame(['example.com', 'example.de', 'intranet.example:8443'], $scope->getHosts());
        self::assertSame(['https://example.com', 'https://example.de', 'https://intranet.example:8443'], $scope->getOrigins());
        self::assertFalse($scope->isEmpty());
        self::assertTrue(new PreviewHosts([])->isEmpty());
    }

    public static function addressesDataProvider(): \Generator
    {
        yield 'host of a site' => ['https://example.de/page', true];
        yield 'host in upper case' => ['https://EXAMPLE.de/page', true];
        yield 'host with its port' => ['https://intranet.example:8443/', true];
        yield 'host without its port' => ['https://intranet.example/', false];
        yield 'another host' => ['https://example.org/', false];
        yield 'user information' => ['https://editor@example.com/', false];
        yield 'another scheme' => ['wss://example.com/', false];
    }

    #[Test]
    #[DataProvider('addressesDataProvider')]
    public function addressesOfTheHostsOfTheScopeAreAllowed(string $address, bool $allowed): void
    {
        self::assertSame($allowed, $this->createScope()->allowsUri(new Uri($address)));
    }

    public static function addressesAsWrittenDataProvider(): \Generator
    {
        yield 'absolute address of a host of the scope' => ['https://example.de/page', true];
        yield 'address relative to the request' => ['/page?a=1', true];
        yield 'address relative to the scheme on a host of the scope' => ['//example.de/page', true];
        yield 'address relative to the scheme on another host' => ['//example.org/page', false];
        yield 'address of another host' => ['https://example.org/page', false];
        yield 'mail address' => ['mailto:info@example.com?subject=Hello', false];
        yield 'phone number' => ['tel:+49-123-456', false];
        yield 'script' => ['javascript:alert(1)', false];
        yield 'malformed address' => ['https://[example.com/', false];
    }

    /**
     * Links, forms and redirects carry addresses as written: relative to the request, of
     * another scheme or malformed. Only addresses on a host of the scope may carry the
     * token, and no address may stop the page from rendering.
     */
    #[Test]
    #[DataProvider('addressesAsWrittenDataProvider')]
    public function addressesAsWrittenAreAllowedOnlyOnHostsOfTheScope(string $address, bool $allowed): void
    {
        self::assertSame($allowed, $this->createScope()->allowsUrl($address, new Uri('https://example.com/current')));
    }

    public static function addressesBrowsersReadDifferentlyDataProvider(): \Generator
    {
        yield 'backslash after the leading slash' => ['/\\evil.example/x', false];
        yield 'two backslashes' => ['\\\\evil.example/x', false];
        yield 'backslashes after the scheme' => ['https:\\\\evil.example/x', false];
        yield 'space before an address relative to the scheme' => [' //evil.example/x', false];
        yield 'tab before an address relative to the scheme' => ["\t//evil.example/x", false];
        yield 'control character before an address relative to the scheme' => ["\x01//evil.example/x", false];
        yield 'tab between the slashes' => ["/\t/evil.example/x", false];
        yield 'line break between the slashes' => ["/\n/evil.example/x", false];
        yield 'carriage return between the slashes' => ["/\r/evil.example/x", false];
        yield 'space before a script' => [' javascript:alert(1)', false];
        yield 'space at the end' => ['/page ', false];
        yield 'backslash in the query' => ['/search?q=a\\b', true];
        yield 'backslash in the fragment' => ['/page#a\\b', true];
    }

    /**
     * Browsers read an address differently from a URI parser: they remove tabs and line
     * breaks, strip control characters and spaces at both ends, and take a backslash before
     * the query for a slash. "/\evil.example/x" is a path to PHP and the host evil.example
     * to a browser. Such an address never carries the token, so a link, a form or a redirect
     * of an extension cannot hand it to another host. A backslash in the query or the
     * fragment changes no host and keeps the token.
     */
    #[Test]
    #[DataProvider('addressesBrowsersReadDifferentlyDataProvider')]
    public function addressesBrowsersReadDifferentlyAreNotAllowed(string $address, bool $allowed): void
    {
        self::assertSame($allowed, $this->createScope()->allowsUrl($address, new Uri('https://example.com/current')));
    }

    public static function plainHttpDataProvider(): \Generator
    {
        yield 'address on plain HTTP of a host whose site uses HTTPS' => ['http://secure.example/page', false];
        yield 'address relative to the scheme from a page on plain HTTP to a host whose site uses HTTPS' => ['//secure.example/page', false];
        yield 'address on HTTPS of a host whose site uses HTTPS' => ['https://secure.example/page', true];
        yield 'address on plain HTTP of a host whose site uses plain HTTP' => ['http://plain.example/page', true];
        yield 'address on HTTPS of a host whose site uses plain HTTP' => ['https://plain.example/page', true];
        yield 'address on plain HTTP of a host with sites on both' => ['http://mixed.example/page', true];
    }

    /**
     * An editor may write down an address of a host of the scope with "http://", for
     * example in a link page, while the site of that host runs on HTTPS. The browser would
     * send the token in the clear before the site redirects to HTTPS, so such an address
     * never carries it. A host with a site on plain HTTP keeps the token on plain HTTP:
     * that site is previewed there anyway.
     */
    #[Test]
    #[DataProvider('plainHttpDataProvider')]
    public function addressesOnPlainHttpAreNotAllowedOnHostsWhoseSitesUseHttpsOnly(string $address, bool $allowed): void
    {
        self::assertSame($allowed, $this->createScopeWithSchemes()->allowsUrl($address, new Uri('http://plain.example/current')));
    }

    #[Test]
    public function hostsWhoseSitesUseHttpsOnlyAreListed(): void
    {
        $scope = $this->createScopeWithSchemes();

        self::assertSame(['secure.example'], $scope->getHttpsOnlyHosts());
        self::assertFalse($scope->allowsUri(new Uri('http://secure.example/')));
        self::assertTrue($scope->allowsUri(new Uri('http://mixed.example/')));
    }

    private function createScopeWithSchemes(): PreviewHosts
    {
        return new PreviewHosts([
            ['site' => 'secure', 'host' => 'secure.example', 'origin' => 'https://secure.example'],
            ['site' => 'plain', 'host' => 'plain.example', 'origin' => 'http://plain.example'],
            ['site' => 'mixed', 'host' => 'mixed.example', 'origin' => 'https://mixed.example'],
            ['site' => 'mixed', 'host' => 'mixed.example', 'origin' => 'http://mixed.example'],
        ]);
    }

    /**
     * A site whose base names an internationalized domain name is requested by browsers
     * with the ASCII form of the host, as the site matching of TYPO3 compares it.
     */
    #[Test]
    public function internationalizedDomainNamesAreComparedInTheirAsciiForm(): void
    {
        $base = new Uri('https://Bücher.example:8443/');
        $scope = new PreviewHosts([
            ['site' => 'books', 'host' => PreviewHosts::hostOf($base), 'origin' => 'https://xn--bcher-kva.example:8443'],
        ]);

        self::assertSame('xn--bcher-kva.example:8443', PreviewHosts::hostOf($base));
        self::assertSame('xn--bcher-kva.example', PreviewHosts::normalizeHost('BÜCHER.example'));
        self::assertTrue($scope->allowsUri(new Uri('https://xn--bcher-kva.example:8443/page')));
        self::assertTrue($scope->allowsUri(new Uri('https://bücher.example:8443/page')));
        self::assertFalse($scope->allowsUri(new Uri('https://xn--bcher-kva.example/page')));
    }

    /**
     * @return list<array{site: string, host: string, origin: string}>
     */
    private static function basesOfSites(int $count): array
    {
        $bases = [];
        for ($i = 1; $i <= $count; $i++) {
            $bases[] = ['site' => 'site' . $i, 'host' => 'site' . $i . '.example', 'origin' => 'https://site' . $i . '.example'];
        }
        return $bases;
    }

    /**
     * The backend page names every origin of the preview in frame-src, so its frame may
     * move to any host of the preview.
     */
    #[Test]
    public function frameSourcesAreTheOriginsUpToTheLimit(): void
    {
        $sources = new PreviewHosts(self::basesOfSites(PreviewHosts::MAX_FRAME_ORIGINS))->getFrameSources();

        self::assertCount(PreviewHosts::MAX_FRAME_ORIGINS, $sources);
        self::assertEquals(new UriValue('https://site1.example'), $sources[0]);
    }

    /**
     * An administrator of many sites would get a frame-src header longer than common header
     * buffers of web servers (about 8 KB for 200 origins). Above the limit the backend page
     * allows the schemes of the origins instead of the list.
     */
    #[Test]
    public function frameSourcesAreTheSchemesAboveTheLimit(): void
    {
        $sources = new PreviewHosts(self::basesOfSites(PreviewHosts::MAX_FRAME_ORIGINS + 1))->getFrameSources();

        self::assertSame([SourceScheme::https], $sources);
    }

    #[Test]
    public function frameSourcesAboveTheLimitAllowPlainHttpOnlyWhereASiteUsesIt(): void
    {
        $bases = self::basesOfSites(PreviewHosts::MAX_FRAME_ORIGINS);
        $bases[] = ['site' => 'legacy', 'host' => 'legacy.example', 'origin' => 'http://legacy.example'];

        $sources = new PreviewHosts($bases)->getFrameSources();

        self::assertSame([SourceScheme::https, SourceScheme::http], $sources);
    }

    #[Test]
    public function frameSourcesCountEachOriginOnce(): void
    {
        $bases = self::basesOfSites(PreviewHosts::MAX_FRAME_ORIGINS);
        $bases[] = ['site' => 'site1-de', 'host' => 'site1.example', 'origin' => 'https://site1.example'];

        self::assertCount(PreviewHosts::MAX_FRAME_ORIGINS, new PreviewHosts($bases)->getFrameSources());
    }
}
