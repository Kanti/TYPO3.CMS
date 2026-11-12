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

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Clock\SystemClock;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * An installation that sets its own list of parameters excluded from the cHash
 * replaces the default list, as settings.php overrides it entry by entry.
 */
final class PreviewAccessCacheHashTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
    private const BACKEND = 'https://backend.example';

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    ];

    protected array $configurationToUseInTestInstance = [
        'FE' => [
            'cacheHash' => [
                'excludedParameters' => ['utm_source'],
                'enforceValidation' => true,
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $pages->update('pages', ['slug' => '/'], ['uid' => 1]);
        $pages->update('pages', ['slug' => '/hidden', 'hidden' => 1], ['uid' => 2]);
        $pages->update('pages', ['slug' => '/other'], ['uid' => 5]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'pid' => 0, 'username' => 'admin', 'admin' => 1]);
        $setup = 'page = PAGE' . LF
            . 'page.10 = TEXT' . LF . 'page.10.data = page:title' . LF
            . 'page.20 = TEXT' . LF . 'page.20.value = With arguments' . LF
            . 'page.20.typolink.parameter = 5' . LF . 'page.20.typolink.additionalParams = &tx_test[item]=7' . LF;
        $this->setUpFrontendRootPage(1, [], ['config' => $setup]);
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
    }

    #[Test]
    public function previewWorksWithAnOwnListOfExcludedParameters(): void
    {
        self::assertNotContains('__t3ct', $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters']);

        $response = $this->preview('https://first.example/hidden', $this->sign());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-2', (string)$response->getBody());
    }

    #[Test]
    public function linkWithCacheHashKeepsWorkingInThePreview(): void
    {
        $jwt = $this->sign();
        $body = (string)$this->preview('https://first.example/', $jwt)->getBody();
        self::assertSame(1, preg_match('#href="([^"]*/other[^"]*)"#', $body, $matches));
        $link = html_entity_decode($matches[1]);
        self::assertStringContainsString('cHash=', $link);
        self::assertStringContainsString('__t3ct=', $link);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://first.example' . $link)
                ->withHeader('User-Agent', self::USER_AGENT)
                ->withHeader('Sec-Fetch-Dest', 'iframe')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Dummy 1-5', (string)$response->getBody());
    }

    #[Test]
    public function renewedTokenKeepsTheCacheHashOfALinkValid(): void
    {
        $body = (string)$this->preview('https://first.example/', $this->sign())->getBody();
        self::assertSame(1, preg_match('#href="([^"]*/other[^"]*)"#', $body, $matches));
        $link = PreviewTokenService::withToken(new Uri(html_entity_decode($matches[1])), $this->sign());

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://first.example' . $link)
                ->withHeader('User-Agent', self::USER_AGENT)
                ->withHeader('Sec-Fetch-Dest', 'iframe')
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function preview(string $url, string $jwt): ResponseInterface
    {
        return $this->executeFrontendSubRequest(
            new InternalRequest($url . (str_contains($url, '?') ? '&' : '?') . '__t3ct=' . $jwt)
                ->withHeader('User-Agent', self::USER_AGENT)
                ->withHeader('Sec-Fetch-Dest', 'iframe')
        );
    }

    private function sign(): string
    {
        return new PreviewTokenService(new SystemClock())
            ->sign(new PreviewProfile(0, true, false, 0, []), self::BACKEND, self::USER_AGENT, 1)
            ->jwt;
    }
}
