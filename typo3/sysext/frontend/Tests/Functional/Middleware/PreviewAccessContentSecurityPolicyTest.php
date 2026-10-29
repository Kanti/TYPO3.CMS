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

namespace TYPO3\CMS\Frontend\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Clock\SystemClock;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The preview adds a script to every page it shows in the frame and a script to its
 * error page. Both have to run under the Content-Security-Policy of the site, whether
 * it allows scripts by nonce, by hash, with 'strict-dynamic' or not at all.
 *
 * Each policy is a site of its own: the policies of all sites are resolved once per
 * process, so a site written later in a test would keep the policy of the first test.
 */
final class PreviewAccessContentSecurityPolicyTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
    private const BACKEND = 'https://backend.example';
    private const SITE_HASH = "'sha256-B2yPHKaXnvFWtRChIbabYmUBFZdVfKKXHbWtWidDVF8='";
    private const NAVIGATION_SCRIPT = 'EXT:frontend/Resources/Public/JavaScript/preview-navigation.js';

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => '4408d27a916d51e624b69af3554f516dbab61037a9f7b9fd6f81b4d3bedeccb6',
            'caching' => [
                'cacheConfigurations' => [
                    'pages' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
                    ],
                ],
            ],
        ],
    ];

    /**
     * @return array<string, array|null> site policies (`csp.yaml`) by site identifier
     */
    private static function sitePolicies(): array
    {
        return [
            'no-policy' => null,
            'default' => ['enforce' => true],
            'nonce-on' => ['enforce' => true, 'behavior' => ['useNonce' => true]],
            'strict-dynamic' => ['enforce' => ['mutations' => [
                ['mode' => 'extend', 'directive' => 'script-src', 'sources' => ["'strict-dynamic'"]],
            ]]],
            'site-hash' => ['enforce' => ['mutations' => [
                ['mode' => 'extend', 'directive' => 'script-src', 'sources' => [self::SITE_HASH]],
            ]]],
            'hashes' => ['enforce' => true, 'behavior' => ['useNonce' => false, 'useHash' => true]],
            'hashes-strict-dynamic' => [
                'enforce' => ['mutations' => [
                    ['mode' => 'extend', 'directive' => 'script-src', 'sources' => ["'strict-dynamic'"]],
                ]],
                'behavior' => ['useNonce' => false, 'useHash' => true],
            ],
            'nonce-off' => ['enforce' => true, 'behavior' => ['useNonce' => false]],
            'hash-off' => ['enforce' => true, 'behavior' => ['useHash' => false]],
            'only-hashes' => [
                'enforce' => [
                    'inheritDefault' => false,
                    'mutations' => [
                        ['mode' => 'set', 'directive' => 'default-src', 'sources' => ["'none'"]],
                        ['mode' => 'set', 'directive' => 'script-src', 'sources' => [self::SITE_HASH]],
                    ],
                ],
                'behavior' => ['useNonce' => false, 'useHash' => true],
            ],
            'only-nonces' => [
                'enforce' => [
                    'inheritDefault' => false,
                    'mutations' => [
                        ['mode' => 'set', 'directive' => 'default-src', 'sources' => ["'none'"]],
                        ['mode' => 'set', 'directive' => 'script-src', 'sources' => ["'nonce-proxy'"]],
                    ],
                ],
            ],
            'script-src-elem' => ['enforce' => ['mutations' => [
                ['mode' => 'set', 'directive' => 'script-src-elem', 'sources' => ["'nonce-proxy'", self::SITE_HASH]],
            ]]],
            'reported' => ['report' => true],
            'reported-hashes' => ['report' => true, 'behavior' => ['useNonce' => false, 'useHash' => true]],
            'enforced-reported' => ['enforce' => true, 'report' => true],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'pid' => 0, 'username' => 'admin', 'admin' => 1]);
        $pages = $connectionPool->getConnectionForTable('pages');
        $rootPageId = 100;
        foreach (self::sitePolicies() as $identifier => $policy) {
            $pages->insert('pages', ['uid' => $rootPageId, 'pid' => 0, 'title' => 'Root ' . $identifier, 'slug' => '/', 'is_siteroot' => 1, 'perms_everybody' => 15]);
            $pages->insert('pages', ['uid' => $rootPageId + 1, 'pid' => $rootPageId, 'title' => 'Hidden ' . $identifier, 'slug' => '/hidden', 'hidden' => 1, 'perms_everybody' => 15]);
            $this->setUpFrontendRootPage($rootPageId, [], ['config' => 'page = PAGE' . LF . 'page.10 = TEXT' . LF . 'page.10.data = page:title']);
            $this->writeSiteConfiguration(
                $identifier,
                $this->buildSiteConfiguration($rootPageId, 'https://' . $identifier . '.example/'),
                [$this->buildDefaultLanguageConfiguration('EN', '/')],
                csp: $policy
            );
            $rootPageId += 2;
        }
    }

    public static function enforcedPoliciesDataProvider(): \Generator
    {
        foreach (array_keys(self::sitePolicies()) as $identifier) {
            if (!str_starts_with($identifier, 'reported')) {
                yield $identifier => [$identifier];
            }
        }
    }

    #[Test]
    #[DataProvider('enforcedPoliciesDataProvider')]
    public function navigationScriptRunsUnderThePolicyOfTheSite(string $site): void
    {
        $url = 'https://' . $site . '.example/hidden';

        $response = $this->preview($url);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hidden ' . $site, (string)$response->getBody());
        $this->assertPolicyOfTheSiteIsSent($site, $response);
        $script = $this->findNavigationScript($response);
        foreach ($response->getHeader('Content-Security-Policy') as $policy) {
            self::assertTrue(self::allowsScript($policy, $url, $script), 'Blocked by "' . $policy . '": ' . json_encode($script));
        }
        self::assertTrue($this->allowsFrameByBackend($response), 'frame-ancestors misses the backend');
    }

    #[Test]
    #[DataProvider('enforcedPoliciesDataProvider')]
    public function errorPageScriptRunsUnderThePolicyOfTheErrorPage(string $site): void
    {
        $url = 'https://' . $site . '.example/hidden';

        $response = $this->preview($url, $this->signExpired());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, preg_match('/<script>(.*?)<\/script>/s', (string)$response->getBody(), $matches));
        self::assertStringContainsString('typo3:preview:expired', $matches[1]);
        self::assertNotSame([], $response->getHeader('Content-Security-Policy'));
        foreach ($response->getHeader('Content-Security-Policy') as $policy) {
            self::assertTrue(self::allowsScript($policy, $url, [], $matches[1]), 'Blocked by "' . $policy . '"');
        }
        self::assertTrue($this->allowsFrameByBackend($response), 'frame-ancestors misses the backend');
    }

    public static function reportedPoliciesDataProvider(): \Generator
    {
        yield 'reported' => ['reported'];
        yield 'reported-hashes' => ['reported-hashes'];
        yield 'enforced-reported' => ['enforced-reported'];
    }

    #[Test]
    #[DataProvider('reportedPoliciesDataProvider')]
    public function navigationScriptCausesNoReportedViolation(string $site): void
    {
        $url = 'https://' . $site . '.example/hidden';

        $response = $this->preview($url);

        $script = $this->findNavigationScript($response);
        self::assertNotSame([], $response->getHeader('Content-Security-Policy-Report-Only'));
        foreach ($response->getHeader('Content-Security-Policy-Report-Only') as $policy) {
            self::assertStringContainsString('script-src', $policy);
            self::assertTrue(self::allowsScript($policy, $url, $script), 'Reported by "' . $policy . '"');
        }
        self::assertTrue($this->allowsFrameByBackend($response), 'an enforced frame-ancestors with the backend is missing');
    }

    #[Test]
    public function navigationScriptCarriesTheNonceOfThePolicy(): void
    {
        $response = $this->preview('https://default.example/hidden');

        $script = $this->findNavigationScript($response);
        self::assertNotSame('', $script['nonce'] ?? '');
        self::assertStringContainsString("'nonce-" . $script['nonce'] . "'", $response->getHeaderLine('Content-Security-Policy'));
    }

    #[Test]
    public function navigationScriptGetsAFreshNonceOnEveryRequest(): void
    {
        $first = $this->findNavigationScript($this->preview('https://default.example/hidden'));
        $second = $this->findNavigationScript($this->preview('https://default.example/hidden'));

        self::assertNotSame($first['nonce'], $second['nonce']);
    }

    public static function hashedPoliciesDataProvider(): \Generator
    {
        yield 'hashes' => ['hashes'];
        yield 'hashes-strict-dynamic' => ['hashes-strict-dynamic'];
        yield 'only-hashes' => ['only-hashes'];
        yield 'reported-hashes' => ['reported-hashes'];
    }

    #[Test]
    #[DataProvider('hashedPoliciesDataProvider')]
    public function navigationScriptIsAllowedByItsHashWhereTheSiteUsesHashes(string $site): void
    {
        $response = $this->preview('https://' . $site . '.example/hidden');

        $script = $this->findNavigationScript($response);
        $fileHash = 'sha256-' . base64_encode(hash_file('sha256', GeneralUtility::getFileAbsFileName(self::NAVIGATION_SCRIPT), true));
        self::assertSame($fileHash, $script['integrity'] ?? null);
        $policies = $response->getHeaderLine('Content-Security-Policy') . $response->getHeaderLine('Content-Security-Policy-Report-Only');
        self::assertStringContainsString("'" . $fileHash . "'", $policies);
        self::assertStringNotContainsString("'nonce-", $policies);
    }

    public static function policiesWithoutHashesDataProvider(): \Generator
    {
        yield 'no-policy' => ['no-policy'];
        yield 'default' => ['default'];
        yield 'hash-off' => ['hash-off'];
        yield 'site-hash' => ['site-hash'];
    }

    /**
     * An integrity attribute blocks the script wherever a proxy changes the file, so it
     * is only added where the policy lists the hash.
     */
    #[Test]
    #[DataProvider('policiesWithoutHashesDataProvider')]
    public function navigationScriptHasNoIntegrityWhereThePolicyHasNoHashOfIt(string $site): void
    {
        $response = $this->preview('https://' . $site . '.example/hidden');

        self::assertArrayNotHasKey('integrity', $this->findNavigationScript($response));
    }

    public static function cachedPoliciesDataProvider(): \Generator
    {
        yield 'default' => ['default'];
        yield 'hashes' => ['hashes'];
    }

    #[Test]
    #[DataProvider('cachedPoliciesDataProvider')]
    public function navigationScriptRunsOnAPageThePageCacheHolds(string $site): void
    {
        $url = 'https://' . $site . '.example/';
        $visitor = $this->executeFrontendSubRequest(new InternalRequest($url));
        self::assertSame(200, $visitor->getStatusCode());
        self::assertSame(1, $this->countPageCacheEntries());

        $response = $this->preview($url);

        $this->assertPolicyOfTheSiteIsSent($site, $response);
        foreach ($response->getHeader('Content-Security-Policy') as $policy) {
            self::assertTrue(self::allowsScript($policy, $url, $this->findNavigationScript($response)), 'Blocked by "' . $policy . '"');
        }
        self::assertSame(1, $this->countPageCacheEntries());
    }

    #[Test]
    #[DataProvider('cachedPoliciesDataProvider')]
    public function policyOfVisitorsKeepsNeitherNonceNorHashOfThePreview(string $site): void
    {
        $url = 'https://' . $site . '.example/';
        $script = $this->findNavigationScript($this->preview($url));

        $visitor = $this->executeFrontendSubRequest(new InternalRequest($url));

        self::assertSame(200, $visitor->getStatusCode());
        self::assertStringNotContainsString('preview-navigation', (string)$visitor->getBody());
        self::assertStringNotContainsString("'nonce-" . $script['nonce'] . "'", $visitor->getHeaderLine('Content-Security-Policy'));
        if (isset($script['integrity'])) {
            self::assertStringNotContainsString($script['integrity'], $visitor->getHeaderLine('Content-Security-Policy'));
        }
    }

    private function preview(string $url, ?string $jwt = null): ResponseInterface
    {
        $jwt ??= new PreviewTokenService(new SystemClock())
            ->sign($this->createProfile(), self::BACKEND, self::USER_AGENT, 1)
            ->jwt;
        return $this->executeFrontendSubRequest(
            new InternalRequest($url . '?__t3ct=' . $jwt)
                ->withHeader('User-Agent', self::USER_AGENT)
                ->withHeader('Sec-Fetch-Dest', 'iframe')
        );
    }

    private function signExpired(): string
    {
        $clock = new readonly class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('-1 hour');
            }
        };
        return new PreviewTokenService($clock)->sign($this->createProfile(), self::BACKEND, self::USER_AGENT, 1)->jwt;
    }

    private function createProfile(): PreviewProfile
    {
        return PreviewProfile::visitor();
    }

    /**
     * Guards the other assertions: a response with only the frame-ancestors of the
     * preview allows every script.
     */
    private function assertPolicyOfTheSiteIsSent(string $site, ResponseInterface $response): void
    {
        if (self::sitePolicies()[$site] === null) {
            self::assertSame('frame-ancestors ' . self::BACKEND, $response->getHeaderLine('Content-Security-Policy'));
            return;
        }
        self::assertMatchesRegularExpression('/(^|; )(default-src|script-src|script-src-elem) /', $response->getHeaderLine('Content-Security-Policy'));
    }

    /**
     * @return array<string, string>
     */
    private function findNavigationScript(ResponseInterface $response): array
    {
        self::assertSame(1, preg_match('/<script\b[^>]*\bdata-preview-backend="[^"]*"[^>]*>/', (string)$response->getBody(), $tag));
        preg_match_all('/\s([a-z-]+)="([^"]*)"/', $tag[0], $attributes, PREG_SET_ORDER);
        $script = [];
        foreach ($attributes as [, $name, $value]) {
            $script[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        }
        self::assertStringEndsWith('preview-navigation.js', (string)parse_url($script['src'], PHP_URL_PATH));
        return $script;
    }

    private function allowsFrameByBackend(ResponseInterface $response): bool
    {
        foreach ($response->getHeader('Content-Security-Policy') as $policy) {
            if (in_array(self::BACKEND, self::parsePolicy($policy)['frame-ancestors'] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    private function countPageCacheEntries(): int
    {
        $identifiers = $this->get(ConnectionPool::class)->getConnectionForTable('cache_pages')
            ->select(['identifier'], 'cache_pages')->fetchFirstColumn();
        return count(array_filter($identifiers, static fn(string $identifier): bool => !str_starts_with($identifier, 'redirects_')));
    }

    /**
     * Whether a browser runs the script element under the policy, as CSP Level 3 decides
     * for script elements: an external script by nonce, by the hash of its integrity
     * attribute or by its origin unless 'strict-dynamic' is set, an inline script by
     * nonce, by hash or by 'unsafe-inline' where no nonce or hash is listed.
     *
     * @param array<string, string> $script attributes of the element
     */
    private static function allowsScript(string $policy, string $documentUrl, array $script, ?string $inlineContent = null): bool
    {
        $directives = self::parsePolicy($policy);
        $sources = $directives['script-src-elem'] ?? $directives['script-src'] ?? $directives['default-src'] ?? null;
        if ($sources === null) {
            return true;
        }
        if (($script['nonce'] ?? '') !== '' && in_array("'nonce-" . $script['nonce'] . "'", $sources, true)) {
            return true;
        }
        $hasNonceOrHash = preg_grep("/^'(nonce|sha256|sha384|sha512)-/", $sources) !== [];
        $strictDynamic = in_array("'strict-dynamic'", $sources, true);
        if ($inlineContent !== null) {
            if (in_array("'sha256-" . base64_encode(hash('sha256', $inlineContent, true)) . "'", $sources, true)) {
                return true;
            }
            return in_array("'unsafe-inline'", $sources, true) && !$hasNonceOrHash && !$strictDynamic;
        }
        if (($script['integrity'] ?? '') !== '' && in_array("'" . $script['integrity'] . "'", $sources, true)) {
            return true;
        }
        if ($strictDynamic) {
            return false;
        }
        $documentOrigin = self::origin($documentUrl);
        $scriptOrigin = str_starts_with($script['src'], '/') && !str_starts_with($script['src'], '//')
            ? $documentOrigin
            : self::origin($script['src']);
        return in_array('*', $sources, true)
            || in_array($scriptOrigin, $sources, true)
            || (in_array("'self'", $sources, true) && $scriptOrigin === $documentOrigin);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parsePolicy(string $policy): array
    {
        $directives = [];
        foreach (explode(';', $policy) as $directive) {
            $tokens = preg_split('/\s+/', trim($directive), -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens === [] || $tokens === false) {
                continue;
            }
            $name = strtolower(array_shift($tokens));
            $directives[$name] ??= $tokens;
        }
        return $directives;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
