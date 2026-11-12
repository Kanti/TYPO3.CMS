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

namespace TYPO3\CMS\Backend\Tests\Unit\Preview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Preview\BackendLoginCookieScope;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BackendLoginCookieScopeTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['cookieDomain'], $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieDomain']);
        parent::tearDown();
    }

    public static function sitesDataProvider(): \Generator
    {
        yield 'site on the host of the backend' => ['', 'backend.example', 'https://backend.example/page', true];
        yield 'site on another host' => ['', 'backend.example', 'https://site.example/page', false];
        yield 'site on a subdomain without a shared cookie domain' => ['', 'backend.example', 'https://www.backend.example/page', false];
        yield 'site on a subdomain of the shared cookie domain' => ['.example.org', 'backend.example.org', 'https://www.example.org/page', true];
        yield 'site outside the shared cookie domain' => ['.example.org', 'backend.example.org', 'https://example.com/page', false];
        yield 'relative address of a site without host' => ['', 'backend.example', '/page', true];
        yield 'site with an internationalized domain name on the host of the backend' => ['', 'xn--bcher-kva.example', 'https://Bücher.example/page', true];
        yield 'site with an internationalized domain name below a shared cookie domain written the same way' => ['.bücher.example', 'backend.xn--bcher-kva.example', 'https://shop.bücher.example/page', true];
        yield 'site with an internationalized domain name below a shared cookie domain in its ASCII form' => ['.xn--bcher-kva.example', 'backend.xn--bcher-kva.example', 'https://shop.bücher.example/page', true];
        yield 'site with another internationalized domain name' => ['.bücher.example', 'backend.xn--bcher-kva.example', 'https://shop.bucher.example/page', false];
    }

    /**
     * Browsers send the host of an internationalized domain name in its ASCII form, so the
     * backend login cookie is set for that form, while the base of a site may name the
     * host as editors write it. Both are compared in the ASCII form, as the site
     * matching of TYPO3 does: otherwise a site on the host of the backend would need a
     * preview token, which the site then refuses on the host the browser requests.
     */
    #[Test]
    #[DataProvider('sitesDataProvider')]
    public function loginCookieReachesTheSitesTheBrowserSendsItTo(string $cookieDomain, string $backendHost, string $site, bool $reaches): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['cookieDomain'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieDomain'] = $cookieDomain;
        $backendRequest = new NormalizedParams(['HTTP_HOST' => $backendHost, 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'], [], '/var/www/html/typo3/index.php', '/var/www/html');

        self::assertSame($reaches, new BackendLoginCookieScope()->reaches($backendRequest, new Uri($site)));
    }

    public static function pathsAndSchemesDataProvider(): \Generator
    {
        yield 'site below the path of an installation in a subdirectory' => ['/cms/typo3/index.php', true, 'https://backend.example/cms/page', true];
        yield 'site outside the path of an installation in a subdirectory' => ['/cms/typo3/index.php', true, 'https://backend.example/page', false];
        yield 'site whose path only starts like the path of the cookie' => ['/cms/typo3/index.php', true, 'https://backend.example/cms-shop/page', false];
        yield 'relative address below the path of the cookie' => ['/cms/typo3/index.php', true, '/cms/page', true];
        yield 'site on plain HTTP with a backend on HTTPS' => ['/typo3/index.php', true, 'http://backend.example/page', false];
        yield 'site on plain HTTP with a backend on plain HTTP' => ['/typo3/index.php', false, 'http://backend.example/page', true];
    }

    /**
     * Without a cookie domain, the backend sets its login cookie for the path of the
     * installation, and as a secure cookie when it runs on HTTPS. A browser sends it to no
     * other path and to no plain HTTP address, so such a site needs a preview token.
     */
    #[Test]
    #[DataProvider('pathsAndSchemesDataProvider')]
    public function loginCookieReachesOnlyThePathsAndSchemesTheBrowserSendsItTo(string $scriptName, bool $https, string $site, bool $reaches): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['cookieDomain'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieDomain'] = '';
        // The installation lives in /var/www/html, which the web server serves below the
        // path the script name starts with
        $backendRequest = new NormalizedParams(
            array_filter(['HTTP_HOST' => 'backend.example', 'HTTPS' => $https ? 'on' : null, 'SCRIPT_NAME' => $scriptName]),
            [],
            '/var/www/html/typo3/index.php',
            '/var/www/html'
        );

        self::assertSame($reaches, new BackendLoginCookieScope()->reaches($backendRequest, new Uri($site)));
    }
}
