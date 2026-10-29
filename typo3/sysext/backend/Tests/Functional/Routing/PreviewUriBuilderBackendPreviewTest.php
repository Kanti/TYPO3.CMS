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

namespace TYPO3\CMS\Backend\Tests\Functional\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The preview page with a preview token is only used where the backend login
 * does not reach the site and the page needs it to be shown as a preview.
 */
final class PreviewUriBuilderBackendPreviewTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'DE' => ['id' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF8'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_backend_preview.csv');
        $this->setUpBackendUser(1);
        $this->writeSiteConfiguration('backend-host', $this->buildSiteConfiguration(1, 'https://backend.example.com/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
        $this->writeSiteConfiguration('other-domain', $this->buildSiteConfiguration(10, 'https://other.example.org/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
            $this->buildLanguageConfiguration('DE', '/de/'),
        ]);
        $this->writeSiteConfiguration('sibling-host', $this->buildSiteConfiguration(20, 'https://www.example.com/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
        $this->setBackendRequest();
    }

    public static function pagesNeedingThePreviewPageDataProvider(): \Generator
    {
        yield 'hidden page' => [12, 'https://other.example.org/hidden'];
        yield 'page below a hidden page that extends to subpages' => [14, 'https://other.example.org/hidden-branch/below'];
        yield 'page that is not yet published' => [15, 'https://other.example.org/scheduled?ADMCMD_simTime='];
        yield 'page restricted to a frontend user group' => [16, 'https://other.example.org/restricted?ADMCMD_simUser=5'];
    }

    #[Test]
    #[DataProvider('pagesNeedingThePreviewPageDataProvider')]
    public function pageOnAnotherDomainThatNeedsTheBackendLoginOpensThePreviewPage(int $pageId, string $frontendUrl): void
    {
        $this->assertPreviewPage($frontendUrl, PreviewUriBuilder::create($pageId)->buildBackendPreviewUri());
    }

    /**
     * An editor gets the preview page for the same pages as an administrator, within the
     * mounts and the page permissions of the editor; a visible page opens directly.
     */
    #[Test]
    public function editorGetsThePreviewPageForAHiddenPageOfItsMounts(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '10', 'allowed_languages' => '0,1']);
        $connectionPool->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 2, 'pid' => 0, 'username' => 'editor', 'admin' => 0, 'usergroup' => '1']);
        $this->setUpBackendUser(2);

        $this->assertPreviewPage('https://other.example.org/hidden', PreviewUriBuilder::create(12)->buildBackendPreviewUri());
        $this->assertPreviewPage('https://other.example.org/hidden-branch/below', PreviewUriBuilder::create(14)->buildBackendPreviewUri());
        self::assertSame('https://other.example.org/visible', (string)PreviewUriBuilder::create(11)->buildBackendPreviewUri());
    }

    #[Test]
    public function visiblePageOnAnotherDomainOpensDirectly(): void
    {
        self::assertSame('https://other.example.org/visible', (string)PreviewUriBuilder::create(11)->buildBackendPreviewUri());
    }

    #[Test]
    public function hiddenPageOnTheHostOfTheBackendOpensDirectly(): void
    {
        self::assertSame('https://backend.example.com/hidden', (string)PreviewUriBuilder::create(2)->buildBackendPreviewUri());
    }

    #[Test]
    public function hiddenPageOnASiblingHostOpensThePreviewPageWithoutASharedCookieDomain(): void
    {
        $this->assertPreviewPage('https://www.example.com/hidden', PreviewUriBuilder::create(21)->buildBackendPreviewUri());
    }

    public static function cookieDomainsReachingTheSiblingHostDataProvider(): \Generator
    {
        yield 'domain' => ['example.com'];
        yield 'domain with leading dot' => ['.example.com'];
        yield 'regular expression' => ['/\.?example\.com$/'];
    }

    #[Test]
    #[DataProvider('cookieDomainsReachingTheSiblingHostDataProvider')]
    public function hiddenPageOnASiblingHostOpensDirectlyIfTheBackendCookieIsShared(string $cookieDomain): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieDomain'] = $cookieDomain;

        self::assertSame('https://www.example.com/hidden', (string)PreviewUriBuilder::create(21)->buildBackendPreviewUri());
    }

    #[Test]
    public function cookieDomainOfAnotherDomainDoesNotReachTheSite(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieDomain'] = 'example.com';

        $this->assertPreviewPage('https://other.example.org/hidden', PreviewUriBuilder::create(12)->buildBackendPreviewUri());
    }

    #[Test]
    public function visiblePageOnAnotherDomainOpensThePreviewPageInAWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;

        $this->assertPreviewPage('https://other.example.org/visible', PreviewUriBuilder::create(11)->buildBackendPreviewUri());
    }

    #[Test]
    public function backendUserSectionOnAnotherDomainOpensThePreviewPage(): void
    {
        $this->assertPreviewPage('https://other.example.org/backend-user-section', PreviewUriBuilder::create(22)->buildBackendPreviewUri());
    }

    #[Test]
    public function pageBelowABackendUserSectionOnAnotherDomainOpensThePreviewPage(): void
    {
        $this->assertPreviewPage('https://other.example.org/backend-user-section/below', PreviewUriBuilder::create(23)->buildBackendPreviewUri());
    }

    #[Test]
    public function disabledLanguageOnAnotherDomainOpensThePreviewPage(): void
    {
        $this->writeSiteConfiguration('other-domain', $this->buildSiteConfiguration(10, 'https://other.example.org/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
            array_replace($this->buildLanguageConfiguration('DE', '/de/'), ['enabled' => false]),
        ]);

        $this->assertPreviewPage('https://other.example.org/de/visible', PreviewUriBuilder::create(11)->withLanguage(1)->buildBackendPreviewUri());
    }

    #[Test]
    public function visiblePageInAnEnabledLanguageOnAnotherDomainOpensDirectly(): void
    {
        self::assertSame('https://other.example.org/de/visible', (string)PreviewUriBuilder::create(11)->withLanguage(1)->buildBackendPreviewUri());
    }

    #[Test]
    public function hiddenTranslationOnAnotherDomainOpensThePreviewPage(): void
    {
        $this->assertPreviewPage(
            'https://other.example.org/de/hidden-translation',
            PreviewUriBuilder::create(17)->withLanguage(1)->buildBackendPreviewUri()
        );
        self::assertSame('https://other.example.org/translated', (string)PreviewUriBuilder::create(17)->buildBackendPreviewUri());
    }

    #[Test]
    public function hiddenRecordOnAnotherDomainOpensThePreviewPage(): void
    {
        $this->assertPreviewPage(
            'https://other.example.org/visible#c2',
            PreviewUriBuilder::createForRecordPreview('tt_content', 2, 11)->buildBackendPreviewUri()
        );
        self::assertSame(
            'https://other.example.org/visible#c1',
            (string)PreviewUriBuilder::createForRecordPreview('tt_content', ['uid' => 1, 'hidden' => 0], 11)->buildBackendPreviewUri()
        );
    }

    /**
     * Lists build a preview button per row, often many on the same page
     */
    #[Test]
    public function decisionForAPageIsMadeOncePerRequest(): void
    {
        self::assertSame('https://other.example.org/visible', (string)PreviewUriBuilder::create(11)->buildBackendPreviewUri());
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 11]);

        self::assertSame('https://other.example.org/visible', (string)PreviewUriBuilder::create(11)->buildBackendPreviewUri());
        $this->get(CacheManager::class)->getCache('runtime')->flush();
        $this->assertPreviewPage('https://other.example.org/visible', PreviewUriBuilder::create(11)->buildBackendPreviewUri());
    }

    #[Test]
    public function decisionForARecordIsMadeForEachRecordOfAPage(): void
    {
        self::assertSame(
            'https://other.example.org/visible#c1',
            (string)PreviewUriBuilder::createForRecordPreview('tt_content', ['uid' => 1, 'hidden' => 0], 11)->buildBackendPreviewUri()
        );
        $this->assertPreviewPage(
            'https://other.example.org/visible#c2',
            PreviewUriBuilder::createForRecordPreview('tt_content', ['uid' => 2, 'hidden' => 1], 11)->buildBackendPreviewUri()
        );
    }

    #[Test]
    public function dispatcherAttributesFollowTheSameDecision(): void
    {
        $hidden = PreviewUriBuilder::create(12)->withModuleLoading(false)->buildDispatcherDataAttributes();
        $visible = PreviewUriBuilder::create(11)->withModuleLoading(false)->buildDispatcherDataAttributes();

        $this->assertPreviewPage('https://other.example.org/hidden', json_decode($hidden['dispatch-args'], true)[0]);
        self::assertSame('https://other.example.org/visible', json_decode($visible['dispatch-args'], true)[0]);
    }

    #[Test]
    public function frontendRequestGetsTheAddressOfThePage(): void
    {
        $request = new ServerRequest('https://backend.example.com/')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        self::assertSame('https://other.example.org/hidden', (string)PreviewUriBuilder::create(12)->buildBackendPreviewUri());
    }

    private function assertPreviewPage(string $frontendUrl, mixed $uri): void
    {
        $uri = (string)$uri;
        self::assertSame('/typo3/preview', parse_url($uri, PHP_URL_PATH), $uri);
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        self::assertStringStartsWith($frontendUrl, $query['url'] ?? '');
    }

    private function setBackendRequest(): void
    {
        $request = new ServerRequest('https://backend.example.com/typo3/main', 'GET', null, [], ['HTTP_HOST' => 'backend.example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
