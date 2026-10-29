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

namespace TYPO3\CMS\Frontend\Tests\Functional\Authentication;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Clock\SystemClock;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\PreviewBackendUserInitializer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The backend user of a preview only shows pages: it has no right to change anything.
 */
final class PreviewBackendUserAuthenticationTest extends FunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::ADMIN, 'pid' => 0, 'username' => 'admin', 'admin' => 1]);
        $connectionPool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::EDITOR, 'pid' => 0, 'username' => 'editor', 'admin' => 0, 'usergroup' => '1', 'options' => 3, 'workspace_perms' => 1]);
        $connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Editors',
            'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content',
            'tables_modify' => 'pages,tt_content',
            'allowed_languages' => '0',
            'workspace_perms' => 1,
        ]);
    }

    #[Test]
    public function theEditorMayChangePagesWithABackendLogin(): void
    {
        $user = $this->setUpBackendUser(self::EDITOR);

        self::assertTrue($user->check('tables_modify', 'pages'));
        self::assertTrue($user->doesUserHaveAccess($this->getPage(1), Permission::PAGE_EDIT));
        $this->changeTitleOfPageOne($user);
        self::assertSame('Changed', $this->getPage(1)['title']);
    }

    #[Test]
    public function theEditorOfAPreviewMayShowButNotChangePages(): void
    {
        $user = $this->initializePreviewUser(self::EDITOR);

        self::assertFalse($user->check('tables_modify', 'pages'));
        self::assertTrue($user->check('tables_select', 'pages'));
        self::assertTrue($user->doesUserHaveAccess($this->getPage(1), Permission::PAGE_SHOW));
        self::assertFalse($user->doesUserHaveAccess($this->getPage(1), Permission::PAGE_EDIT));
        self::assertFalse($user->doesUserHaveAccess($this->getPage(1), Permission::PAGE_SHOW | Permission::CONTENT_EDIT));
        self::assertSame(Permission::PAGE_SHOW, $user->calcPerms($this->getPage(1)));
    }

    #[Test]
    public function theBackendUserOfAPreviewHasNoFilePermissions(): void
    {
        $user = $this->initializePreviewUser(self::ADMIN);

        self::assertNotSame([], $user->getFilePermissions());
        self::assertNotContains(true, $user->getFilePermissions());
    }

    public static function editorsDataProvider(): \Generator
    {
        yield 'editor' => [self::EDITOR];
        yield 'admin' => [self::ADMIN];
    }

    #[Test]
    #[DataProvider('editorsDataProvider')]
    public function dataHandlerChangesNoRecordForTheBackendUserOfAPreview(int $userId): void
    {
        $user = $this->initializePreviewUser($userId);

        $exception = null;
        try {
            $this->changeTitleOfPageOne($user);
        } catch (\RuntimeException $exception) {
        }

        self::assertSame(1794406762, $exception?->getCode());
        self::assertSame('Root', $this->getPage(1)['title']);
    }

    #[Test]
    public function thePreviewBuildsNoEditorOverABackendUserOfTheRequest(): void
    {
        $this->setUpBackendUser(self::EDITOR);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1794413652);
        $this->initializePreviewUser(self::ADMIN);
    }

    #[Test]
    #[DataProvider('editorsDataProvider')]
    public function dataHandlerRunsNoCommandForTheBackendUserOfAPreview(int $userId): void
    {
        $user = $this->initializePreviewUser($userId);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [5 => ['delete' => 1]]], $user);
        $exception = null;
        try {
            $dataHandler->process_cmdmap();
        } catch (\RuntimeException $exception) {
        }

        self::assertSame(1794406763, $exception?->getCode());
        self::assertSame(0, (int)$this->getPage(5)['deleted']);
    }

    /**
     * The editor of a preview is authenticated by the token alone: no way a normal
     * backend user creates, renews or removes a session or its cookie works for it.
     */
    #[Test]
    public function theBackendUserOfAPreviewCreatesNoBackendSessionOrCookie(): void
    {
        $sessionsBefore = $this->countBackendSessions();
        $user = $this->initializePreviewUser(self::EDITOR);
        $request = new ServerRequest('https://first.example/', 'POST', null, [], ['HTTPS' => 'on', 'HTTP_HOST' => 'first.example'])
            ->withParsedBody(['login_status' => 'login', 'username' => 'admin', 'userident' => 'password'])
            ->withCookieParams(['be_typo_user' => 'cookie-of-another-session']);

        $user->start($request);
        $user->checkAuthentication($request);
        $user->setAndSaveSessionData('key', 'value');
        $user->writeUC();
        $user->enforceNewSessionId();
        $user->logoff();
        $response = $user->appendCookieToResponse(new Response(), NormalizedParams::createFromRequest($request));

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame($sessionsBefore, $this->countBackendSessions());
        self::assertSame(self::EDITOR, (int)($user->user['uid'] ?? 0));
        self::assertSame('value', $user->getSessionData('key'));
    }

    private function countBackendSessions(): int
    {
        return (int)$this->get(ConnectionPool::class)->getConnectionForTable('be_sessions')->count('*', 'be_sessions', []);
    }

    private function initializePreviewUser(int $userId): BackendUserAuthentication
    {
        $token = new PreviewTokenService(new SystemClock())->sign(
            new PreviewProfile(0, false, false, 0, []),
            'https://backend.example',
            'Mozilla/5.0',
            $userId
        );
        return $this->get(PreviewBackendUserInitializer::class)->initialize($token);
    }

    private function changeTitleOfPageOne(BackendUserAuthentication $user): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [1 => ['title' => 'Changed']]], [], $user);
        $dataHandler->process_datamap();
        return $dataHandler;
    }

    private function getPage(int $uid): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('*')->from('pages')->where($queryBuilder->expr()->eq('uid', $uid))->executeQuery()->fetchAssociative();
    }
}
