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
use TYPO3\CMS\Core\Preview\PreviewScope;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PreviewScopeTest extends UnitTestCase
{
    private function createScope(): PreviewScope
    {
        return new PreviewScope([
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
        self::assertTrue(new PreviewScope([])->isEmpty());
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

    /**
     * A site whose base names an internationalized domain name is requested by browsers
     * with the ASCII form of the host, as the site matching of TYPO3 compares it.
     */
    #[Test]
    public function internationalizedDomainNamesAreComparedInTheirAsciiForm(): void
    {
        $base = new Uri('https://Bücher.example:8443/');
        $scope = new PreviewScope([
            ['site' => 'books', 'host' => PreviewScope::hostOf($base), 'origin' => 'https://xn--bcher-kva.example:8443'],
        ]);

        self::assertSame('xn--bcher-kva.example:8443', PreviewScope::hostOf($base));
        self::assertSame('xn--bcher-kva.example', PreviewScope::normalizeHost('BÜCHER.example'));
        self::assertTrue($scope->allowsUri(new Uri('https://xn--bcher-kva.example:8443/page')));
        self::assertTrue($scope->allowsUri(new Uri('https://bücher.example:8443/page')));
        self::assertFalse($scope->allowsUri(new Uri('https://xn--bcher-kva.example/page')));
    }
}
