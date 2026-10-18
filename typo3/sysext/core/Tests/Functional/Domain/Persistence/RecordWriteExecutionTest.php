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

namespace TYPO3\CMS\Core\Tests\Functional\Domain\Persistence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Authentication\SystemUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordAccessDeniedException;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteContext;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordWriteExecutionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'frontend'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/system-user.csv');
        $this->get(\TYPO3\CMS\Core\Configuration\SiteWriter::class)->write('record-write', [
            'rootPageId' => 1, 'base' => 'https://example.org/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
            ],
        ]);
    }

    #[Test]
    public function frontendWritesWithoutBackendAuthenticationAndPreservesGlobals(): void
    {
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $writer = $this->get(RecordWriter::class);
        $record = $writer->save($writer->create('tx_recordwrite_item', 1)->set('title', 'Frontend'));
        self::assertSame('Frontend', $record->get('title'));
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    #[Test]
    public function ordinaryContentColumnsCanBeImported(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $writer = $this->get(RecordWriter::class);
        $record = $writer->save($writer->create('tt_content', 1)->setValues(['CType' => 'text', 'header' => 'Import', 'bodytext' => '<p>Text</p>', 'colPos' => 0]));
        self::assertSame(0, (int)$record->get('colPos'));
    }
    #[Test]
    public function deletedSystemUsersFollowNativeProvisioningWithoutChangingTheDeletedRow(): void
    {
        $editor = $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $writer = $this->get(RecordWriter::class);
        $writer->delete($writer->save($writer->editByUid('be_users', 90)));
        $query = $this->getConnectionPool()->getQueryBuilderForTable('be_users');
        $query->getRestrictions()->removeAll();
        $before = $query->select('*')->from('be_users')->where($query->expr()->eq('uid', 90))->executeQuery()->fetchAssociative();
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $saved = $writer->save($writer->create('tx_recordwrite_item', 1)->set('title', 'Native provisioning'));
        $active = $this->getConnectionPool()->getConnectionForTable('be_users')->select(['uid', 'admin'], 'be_users', ['username' => '_cli_'])->fetchAllAssociative();
        self::assertCount(1, $active);
        self::assertNotSame(90, (int)$active[0]['uid']);
        self::assertSame(1, (int)$active[0]['admin']);
        self::assertSame($before, $query->executeQuery()->fetchAssociative());
        self::assertSame((int)$active[0]['uid'], (int)$this->getConnectionPool()->getConnectionForTable('sys_history')->select(['userid'], 'sys_history', ['tablename' => 'tx_recordwrite_item', 'recuid' => $saved->getUid()])->fetchOne());
        self::assertSame($editor, $GLOBALS['BE_USER']);
    }

    public static function frontendVisitors(): iterable
    {
        yield 'anonymous' => [false, false, false];
        yield 'backend administrator' => [true, false, false];
        yield 'restricted editor' => [true, true, false];
        yield 'logged in frontend visitor' => [false, false, true];
    }

    #[Test]
    #[DataProvider('frontendVisitors')]
    public function frontendIdentityIsIndependentOfVisitorSessions(bool $backend, bool $restricted, bool $frontend): void
    {
        $editor = $backend ? $this->setUpBackendUser(1) : null;
        if (!$backend) {
            unset($GLOBALS['BE_USER']);
        } elseif ($restricted) {
            $editor->user['admin'] = 0;
            $editor->groupData['tables_modify'] = '';
        }
        $visitor = new FrontendUserAuthentication();
        if ($frontend) {
            $visitor->user = ['uid' => 456, 'username' => 'Visitor'];
        }
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)->withAttribute('frontend.user', $visitor);
        $request = $GLOBALS['TYPO3_REQUEST'];
        $context = $this->get(Context::class);
        $context->setAspect('frontend.user', new \TYPO3\CMS\Core\Context\UserAspect($visitor));
        $visitorAspect = $context->getAspect('frontend.user');
        $aspect = $context->getAspect('backend.user');
        $languageService = $GLOBALS['LANG'] ?? null;
        $editorState = $editor !== null ? [$editor->user, $editor->groupData, $editor->workspace] : null;
        $writer = $this->get(RecordWriter::class);
        $record = $writer->save($writer->create('tx_recordwrite_item', 1)->set('title', 'System actor'));
        $record = $writer->save($writer->edit($record)->set('title', 'Changed by system'));
        self::assertSame('Changed by system', $record->get('title'));
        $history = $this->getConnectionPool()->getConnectionForTable('sys_history')->select(['userid'], 'sys_history', ['tablename' => 'tx_recordwrite_item', 'recuid' => $record->getUid()])->fetchFirstColumn();
        self::assertNotEmpty($history);
        self::assertSame([90], array_values(array_unique(array_map('intval', $history))));
        self::assertSame($request, $GLOBALS['TYPO3_REQUEST']);
        self::assertSame($visitor, $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.user'));
        self::assertSame($visitorAspect, $context->getAspect('frontend.user'));
        self::assertSame($aspect, $context->getAspect('backend.user'));
        self::assertSame($languageService, $GLOBALS['LANG'] ?? null);
        if ($editor !== null) {
            self::assertSame($editor, $GLOBALS['BE_USER']);
            self::assertSame($editorState, [$editor->user, $editor->groupData, $editor->workspace]);
        } else {
            self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        }
    }

    #[Test]
    public function establishedCliIdentityIsUsedAndPreserved(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $cli = GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
        $cli->authenticate();
        $GLOBALS['BE_USER'] = $cli;
        $writer = $this->get(RecordWriter::class);
        $record = $writer->save($writer->create('tx_recordwrite_item', 1)->set('title', 'CLI'));
        self::assertSame($cli, $GLOBALS['BE_USER']);
        self::assertSame($cli, RecordWriteContext::capture($this->get(Context::class))->user);
        self::assertSame(90, (int)$this->getConnectionPool()->getConnectionForTable('sys_history')->select(['userid'], 'sys_history', ['tablename' => 'tx_recordwrite_item', 'recuid' => $record->getUid()])->fetchOne());
    }

    #[Test]
    public function implicitCliExecutionUsesTheSameSystemIdentityAsFrontend(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['BE_USER']);
        $context = RecordWriteContext::capture($this->get(Context::class));
        self::assertInstanceOf(SystemUserAuthentication::class, $context->user);
        self::assertSame(90, $context->userId);
        $writer = $this->get(RecordWriter::class);
        self::assertSame('CLI system', $writer->save($writer->create('tx_recordwrite_item', 1)->set('title', 'CLI system'))->get('title'));
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    #[Test]
    public function frontendUsesLiveAndExplicitLanguageWhileRestoringBackendWorkspacePreview(): void
    {
        $editor = $this->setUpBackendUser(1);
        $context = $this->get(Context::class);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $writer = $this->get(RecordWriter::class);
        $original = $writer->save($writer->editByUid('tx_recordwrite_item', 1));
        $translation = $writer->localize($original, 1);
        $editor->workspace = 1;
        $context->setAspect('workspace', new WorkspaceAspect(1));
        $version = $writer->save($writer->edit($translation)->set('title', 'Workspace translation'));
        $workspace = $context->getAspect('workspace');
        $language = new LanguageAspect(1);
        $context->setAspect('language', $language);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $saved = $writer->save($writer->editByUid('tx_recordwrite_item', $translation->getUid())->set('title', 'Live translation'));
        $identity = $writer->identity($saved);
        self::assertSame(1, $identity->languageId);
        self::assertSame(0, $identity->workspaceId);
        self::assertSame($translation->getUid(), $identity->logicalUid);
        self::assertSame(1, $identity->defaultLanguageUid);
        self::assertSame($workspace, $context->getAspect('workspace'));
        self::assertSame($language, $context->getAspect('language'));
        self::assertSame(1, $editor->workspace);
        self::assertSame('Workspace translation', $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->select(['title'], 'tx_recordwrite_item', ['uid' => $version->getUid()])->fetchOne());
    }

    public static function layoutColumns(): iterable
    {
        yield 'default main' => [false, 1, 0, true];
        yield 'default removed column' => [false, 1, 1, false];
        yield 'first layout main' => [true, 1, 0, true];
        yield 'first layout secondary' => [true, 1, 2, true];
        yield 'first layout invalid sidebar' => [true, 1, 1, false];
        yield 'second layout sidebar' => [true, 2, 1, true];
        yield 'second layout extra' => [true, 2, 4, true];
        yield 'second layout invalid main' => [true, 2, 0, false];
    }

    #[Test]
    #[DataProvider('layoutColumns')]
    public function dynamicContentColumnsFollowTheSelectedBackendLayout(bool $layouts, int $pid, int $column, bool $valid): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        if ($layouts) {
            $this->importCSVDataSet(__DIR__ . '/Fixtures/layouts.csv');
        }
        $writer = $this->get(RecordWriter::class);
        if ($layouts) {
            $writer->save($writer->editByUid('pages', 1)->set('backend_layout', '1'));
            $writer->save($writer->editByUid('pages', 2)->set('backend_layout', '2'));
        }
        $draft = $writer->create('tt_content', $pid)->setValues(['CType' => 'text', 'header' => 'Column', 'colPos' => $column]);
        if (!$valid) {
            $this->expectException(InvalidRecordValueException::class);
        }
        $record = $writer->save($draft);
        self::assertSame($column, (int)$record->get('colPos'));
    }

    #[Test]
    public function missingBackendAuthenticationNeverFallsBackToTheSystemIdentity(): void
    {
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->expectException(InvalidWriteContextException::class);
        $this->get(RecordWriter::class)->editByUid('tx_recordwrite_item', 1);
    }

    #[Test]
    public function frontendDraftSurvivesUnrelatedEditorSessionAndWorkspaceChanges(): void
    {
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $writer = $this->get(RecordWriter::class);
        $draft = $writer->create('tx_recordwrite_item', 1)->set('title', 'Stable identity');
        $editor = $this->setUpBackendUser(1);
        $editor->workspace = 1;
        $editor->user['admin'] = 0;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $record = $writer->save($draft);
        self::assertSame(0, $writer->identity($record)->workspaceId);
        self::assertSame($editor, $GLOBALS['BE_USER']);
        self::assertSame(1, $editor->workspace);
    }

    #[Test]
    public function aFrontendDraftCannotBeSavedFromABackendRequest(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $writer = $this->get(RecordWriter::class);
        $draft = $writer->create('tx_recordwrite_item', 1)->set('title', 'Context changed');
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->expectException(InvalidWriteContextException::class);
        $writer->save($draft);
    }

    #[Test]
    public function scopedSystemIdentityRestoresGlobalsAndAspectsAfterNestedExceptions(): void
    {
        $editor = $this->setUpBackendUser(1);
        $context = $this->get(Context::class);
        $context->setAspect('workspace', new WorkspaceAspect(1));
        $editor->workspace = 1;
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $globals = [$GLOBALS['BE_USER'], $GLOBALS['LANG'] ?? null, $GLOBALS['TYPO3_REQUEST']];
        $aspects = [$context->getAspect('workspace'), $context->getAspect('backend.user'), $context->getAspect('language')];
        $write = RecordWriteContext::capture($context);
        try {
            $write->run(function () use ($write, $context): void {
                self::assertSame(90, (int)$GLOBALS['BE_USER']->getUserId());
                self::assertSame(0, (int)$context->getPropertyFromAspect('workspace', 'id'));
                $write->run(static function (): void {
                    throw new \RuntimeException('Nested failure', 1792310030);
                });
            });
            self::fail('Expected the nested failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1792310030, $exception->getCode());
        }
        self::assertSame($globals, [$GLOBALS['BE_USER'], $GLOBALS['LANG'] ?? null, $GLOBALS['TYPO3_REQUEST']]);
        self::assertSame($aspects, [$context->getAspect('workspace'), $context->getAspect('backend.user'), $context->getAspect('language')]);
    }

    public static function unavailableSystemUsers(): iterable
    {
        yield 'disabled' => ['disable', true];
        yield 'expired' => ['endtime', 1700000000];
    }

    #[Test]
    #[DataProvider('unavailableSystemUsers')]
    public function unavailableSystemUserIsRejectedEvenWithFrontendTimePreview(string $field, bool|int $value): void
    {
        $editor = $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $writer = $this->get(RecordWriter::class);
        $writer->save($writer->editByUid('be_users', 90)->set($field, $value));
        $GLOBALS['SIM_ACCESS_TIME'] = 1600000000;
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        try {
            $writer->create('tx_recordwrite_item', 1);
            self::fail('Unavailable system users cannot execute frontend writes.');
        } catch (InvalidWriteContextException) {
            self::assertSame($editor, $GLOBALS['BE_USER']);
        }
    }

    #[Test]
    public function cliLanguageAndWorkspaceRemainExplicit(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $cli = GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
        $cli->authenticate();
        $GLOBALS['BE_USER'] = $cli;
        $writer = $this->get(RecordWriter::class);
        $translation = $writer->localize($writer->save($writer->editByUid('tx_recordwrite_item', 1)), 1);
        $cli->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $version = $writer->save($writer->edit($translation)->set('title', 'CLI workspace translation'));
        $identity = $writer->identity($version);
        self::assertSame($translation->getUid(), $identity->logicalUid);
        self::assertNotSame($identity->logicalUid, $identity->physicalUid);
        self::assertSame(1, $identity->languageId);
        self::assertSame(1, $identity->workspaceId);
        self::assertSame('Original', $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->select(['title'], 'tx_recordwrite_item', ['uid' => $translation->getUid()])->fetchOne());
    }

    #[Test]
    public function coreRecordEditLocksAllowReadsButPreventWrites(): void
    {
        $user = $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $writer = $this->get(RecordWriter::class);
        $record = $writer->save($writer->create('tt_content', 1)->setValues(['CType' => 'text', 'editlock' => true]));
        $user->user['admin'] = 0;
        $user->groupData['webmounts'] = '1';
        $user->groupData['tables_modify'] = 'tt_content';
        $draft = $writer->edit($record);
        self::assertTrue($draft->get('editlock'));
        $this->expectException(RecordAccessDeniedException::class);
        $writer->save($draft->set('header', 'Denied'));
    }

    #[Test]
    public function frontendColumnValidationIgnoresCachedBackendWorkspaceLayouts(): void
    {
        $editor = $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->importCSVDataSet(__DIR__ . '/Fixtures/layouts.csv');
        $writer = $this->get(RecordWriter::class);
        $writer->save($writer->editByUid('pages', 1)->set('backend_layout', '1'));
        $editor->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $writer->save($writer->editByUid('pages', 1)->set('backend_layout', '2'));
        $preview = $writer->save($writer->create('tt_content', 1)->setValues(['CType' => 'text', 'colPos' => 4]));
        self::assertSame(1, $writer->identity($preview)->workspaceId);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $live = $writer->save($writer->create('tt_content', 1)->setValues(['CType' => 'text', 'colPos' => 2]));
        self::assertSame(0, $writer->identity($live)->workspaceId);
        self::assertSame(1, $editor->workspace);
        $this->expectException(InvalidRecordValueException::class);
        $writer->save($writer->create('tt_content', 1)->setValues(['CType' => 'text', 'colPos' => 4]));
    }

    #[Test]
    public function systemIdentityUsesItsStoredPermissionsWithoutElevatingTheCliUser(): void
    {
        $editor = $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $writer = $this->get(RecordWriter::class);
        $writer->save($writer->editByUid('be_users', 90)->setValues(['admin' => false, 'workspace_perms' => true]));
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        try {
            $draft = $writer->editByUid('tx_recordwrite_item', 1);
            self::assertSame('Original', $draft->get('title'));
            $writer->save($draft->set('title', 'Denied system change'));
            self::fail('The system identity must not invent permissions.');
        } catch (RecordAccessDeniedException) {
            self::assertSame($editor, $GLOBALS['BE_USER']);
            self::assertSame(0, (int)$this->getConnectionPool()->getConnectionForTable('be_users')->select(['admin'], 'be_users', ['uid' => 90])->fetchOne());
        }
    }

}
