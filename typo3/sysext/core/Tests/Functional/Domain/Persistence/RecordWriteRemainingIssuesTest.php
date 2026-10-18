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
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\Persistence\Exception\DataHandlerExecutionException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\RecordPosition;
use TYPO3\CMS\Core\Domain\Persistence\RecordReference;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordWriteRemainingIssuesTest extends FunctionalTestCase
{
    private const TABLE = 'tx_recordwrite_item';
    protected array $coreExtensionsToLoad = ['workspaces', 'frontend'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];
    private RecordWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->writer = $this->get(RecordWriter::class);
        $this->get(SiteWriter::class)->write('record-write', [
            'rootPageId' => 1, 'base' => 'https://example.org/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
            ],
        ]);
    }

    #[Test]
    public function frontendAutomaticallyProvisionsAndReusesTheCliIdentity(): void
    {
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $first = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'First use'));
        $second = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Second use'));
        $users = $this->getConnectionPool()->getConnectionForTable('be_users')->select(['uid', 'admin'], 'be_users', ['username' => '_cli_'])->fetchAllAssociative();
        self::assertCount(1, $users);
        self::assertSame(1, (int)$users[0]['admin']);
        foreach ([$first, $second] as $record) {
            self::assertSame((int)$users[0]['uid'], (int)$this->getConnectionPool()->getConnectionForTable('sys_history')->select(['userid'], 'sys_history', ['tablename' => self::TABLE, 'recuid' => $record->getUid()])->fetchOne());
        }
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        unset($GLOBALS['TYPO3_REQUEST']);
        $cli = GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
        $cli->authenticate();
        self::assertSame((int)$users[0]['uid'], (int)$cli->getUserId());
    }

    #[Test]
    public function anEmptyBatchDoesNotInitializeOrProvisionASystemIdentity(): void
    {
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $batch = $this->writer->batch();
        self::assertTrue($batch->validate()->isValid());
        $batch->execute();
        self::assertSame([], $this->cliRows());
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    public static function relationOperations(): iterable
    {
        foreach (['related', 'reference', 'references', 'selected', 'children'] as $field) {
            foreach ([false, true] as $loaded) {
                yield $field . ($loaded ? ' loaded' : ' unloaded') => [$field, $loaded];
            }
        }
    }

    #[Test]
    #[DataProvider('relationOperations')]
    public function incrementalAddsPreserveChangesMadeByAnotherDraft(string $field, bool $loaded): void
    {
        $third = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Third'));
        $a = $this->writer->editByUid(self::TABLE, 1);
        if ($loaded) {
            $a->relations($field);
        }
        $b = $this->writer->editByUid(self::TABLE, 1);
        $b->relations($field)->add(new RecordReference(self::TABLE, 2));
        $this->writer->save($b);
        $a->relations($field)->add($third);
        if ($field === 'reference') {
            // This group field allows one member: a safe merge must fail cardinality validation.
            $this->expectException(InvalidRecordValueException::class);
        }
        $saved = $this->writer->save($a);
        self::assertSame([2, $third->getUid()], array_map(static fn(RecordReference $ref): int => $ref->uid, $this->writer->edit($saved)->relations($field)->toArray()));
    }

    #[Test]
    public function loadedIncrementalRemovePreservesAnUnrelatedAddition(): void
    {
        $one = new RecordReference(self::TABLE, 2);
        $third = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Third'));
        $this->writer->save($this->writer->editByUid(self::TABLE, 1)->setRelation('related', $one));
        $a = $this->writer->editByUid(self::TABLE, 1);
        $a->relations('related')->remove($one);
        $b = $this->writer->editByUid(self::TABLE, 1);
        $b->relations('related')->add($third);
        $this->writer->save($b);
        $saved = $this->writer->save($a);
        self::assertSame([$third->getUid()], array_map(static fn(RecordReference $ref): int => $ref->uid, $this->writer->edit($saved)->relations('related')->toArray()));
    }

    #[Test]
    public function reorderRejectsMembershipChangedAfterLoading(): void
    {
        $ref = new RecordReference(self::TABLE, 2);
        $this->writer->save($this->writer->editByUid(self::TABLE, 1)->setRelation('related', $ref));
        $a = $this->writer->editByUid(self::TABLE, 1);
        $a->relations('related')->reorder([$ref]);
        $third = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Third'));
        $b = $this->writer->editByUid(self::TABLE, 1);
        $b->relations('related')->add($third);
        $this->writer->save($b);
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($a);
    }

    public static function movedColumns(): iterable
    {
        yield 'valid on current page' => [4, true, 0];
        yield 'invalid on current page' => [0, false, 0];
        yield 'valid in workspace' => [4, true, 1];
        yield 'invalid in workspace' => [0, false, 1];
    }

    #[Test]
    #[DataProvider('movedColumns')]
    public function dynamicSelectsUseTheCurrentPageAfterAMove(int $column, bool $valid, int $workspace): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/layouts.csv');
        $this->writer->save($this->writer->editByUid('pages', 1)->set('backend_layout', '1'));
        $this->writer->save($this->writer->editByUid('pages', 2)->set('backend_layout', '2'));
        if ($workspace) {
            $GLOBALS['BE_USER']->workspace = 1;
            $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        }
        $record = $this->writer->save($this->writer->create('tt_content', 1)->setValues(['CType' => 'text', 'colPos' => 0]));
        $draft = $this->writer->edit($record);
        $this->writer->move($record, RecordPosition::onPage(2));
        $draft->set('colPos', $column);
        if (!$valid) {
            $this->expectException(InvalidRecordValueException::class);
        }
        $saved = $this->writer->save($draft);
        self::assertSame(2, $saved->getPid());
        self::assertSame($column, (int)$saved->get('colPos'));
    }

    public static function unavailableIdentities(): iterable
    {
        yield 'disabled' => ['disable', true];
        yield 'expired' => ['endtime', 1700000000];
        yield 'not yet active' => ['starttime', 2100000000];
    }

    #[Test]
    #[DataProvider('unavailableIdentities')]
    public function existingUnavailableIdentitiesAreNotReplacedOrReactivated(string $field, bool|int $value): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/system-user.csv');
        $this->writer->save($this->writer->editByUid('be_users', 90)->set($field, $value));
        $before = $this->cliRows();
        $editor = $GLOBALS['BE_USER'];
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        try {
            $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Unavailable'));
            self::fail('An existing unavailable identity must not be replaced.');
        } catch (InvalidWriteContextException) {
            self::assertCount(1, $this->cliRows());
            self::assertSame($before, $this->cliRows());
            self::assertSame($editor, $GLOBALS['BE_USER']);
        }
    }

    #[Test]
    public function systemSnapshotsAreIsolatedAcrossDraftsAndRequestObjects(): void
    {
        unset($GLOBALS['BE_USER']);
        $request = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $first = $this->writer->create(self::TABLE, 1);
        $second = $this->writer->create(self::TABLE, 1);
        self::assertNotSame($first->context->user, $second->context->user);
        $first->context->user->groupData['tables_modify'] = 'Changed locally';
        self::assertNotSame($first->context->user->groupData, $second->context->user->groupData);
        $weak = \WeakReference::create($request);
        unset($GLOBALS['TYPO3_REQUEST'], $request);
        gc_collect_cycles();
        self::assertNull($weak->get(), 'The request identity cache must not retain completed requests.');
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $third = $this->writer->create(self::TABLE, 1);
        self::assertNotSame($third->context->user, $second->context->user);
        self::assertSame($third->context->user->groupData, $second->context->user->groupData);
    }

    #[Test]
    public function permissionChangesAreRevalidatedOnceBeforeBatchExecution(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/system-user.csv');
        $editor = $GLOBALS['BE_USER'];
        $backendRequest = $GLOBALS['TYPO3_REQUEST'];
        $frontendRequest = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $frontendRequest;
        $batch = $this->writer->batch();
        $batch->create(self::TABLE, 1)->set('title', 'Not authorized anymore');
        $GLOBALS['TYPO3_REQUEST'] = $backendRequest;
        $this->writer->save($this->writer->editByUid('be_users', 90)->setValues(['admin' => false, 'workspace_perms' => true]));
        $GLOBALS['TYPO3_REQUEST'] = $frontendRequest;
        try {
            $batch->execute();
            self::fail('Changed system permissions must invalidate the captured execution context.');
        } catch (InvalidWriteContextException) {
            self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
            self::assertSame($editor, $GLOBALS['BE_USER']);
        }
    }

    #[Test]
    public function explicitMembershipReplacementIntentionallyReplacesConcurrentAdditions(): void
    {
        $ref = new RecordReference(self::TABLE, 2);
        $a = $this->writer->editByUid(self::TABLE, 1);
        $a->relations('related')->setMembers([$ref]);
        $third = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Third'));
        $b = $this->writer->editByUid(self::TABLE, 1);
        $b->relations('related')->add($third);
        $this->writer->save($b);
        $saved = $this->writer->save($a);
        self::assertSame([2], array_map(static fn(RecordReference $ref): int => $ref->uid, $this->writer->edit($saved)->relations('related')->toArray()));
    }

    private function cliRows(): array
    {
        $query = $this->getConnectionPool()->getQueryBuilderForTable('be_users');
        $query->getRestrictions()->removeAll();
        return $query->select('*')->from('be_users')->where($query->expr()->eq('username', $query->createNamedParameter('_cli_')))->executeQuery()->fetchAllAssociative();
    }

    public static function localizedRelationContexts(): iterable
    {
        yield 'live' => [0, 0];
        yield 'workspace' => [1, 0];
        yield 'localized' => [0, 1];
        yield 'localized workspace' => [1, 1];
    }

    #[Test]
    #[DataProvider('staleRelationContexts')]
    public function staleRelationOperationsRetainTheirLanguageAndWorkspace(int $workspace, int $language, string $field = 'related'): void
    {
        $parent = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        if ($language) {
            $parent = $this->writer->localize($parent, 1);
        }
        $third = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Third'));
        $second = $this->writer->save($this->writer->editByUid(self::TABLE, 2));
        if ($field === 'children' && $language) {
            $second = $this->writer->localize($second, $language);
            $third = $this->writer->localize($third, $language);
        }
        $expected = [$second->getUid(), $third->getUid()];
        if ($workspace) {
            $GLOBALS['BE_USER']->workspace = 1;
            $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        }
        if ($workspace && $field === 'children') {
            $second = $this->writer->save($this->writer->edit($second)->set('title', 'Workspace second'));
            $third = $this->writer->save($this->writer->edit($third)->set('title', 'Workspace third'));
        }
        $a = $this->writer->edit($parent);
        $a->relations($field)->add($third);
        $b = $this->writer->edit($parent);
        $b->relations($field)->add($second);
        $this->writer->save($b);
        $saved = $this->writer->save($a);
        self::assertSame($expected, array_map(static fn(RecordReference $ref): int => $ref->uid, $this->writer->edit($saved)->relations($field)->toArray()));
        $identity = $this->writer->identity($saved);
        self::assertSame($workspace, $identity->workspaceId);
        self::assertSame($language, $identity->languageId);
        if ($workspace) {
            $GLOBALS['BE_USER']->workspace = 0;
            $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
            self::assertSame([], $this->writer->edit($parent)->relations($field)->toArray());
        }
    }

    #[Test]
    public function nestedScopeRestorationSurvivesAnInnerAuthenticationChangeAndException(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/system-user.csv');
        $editor = $GLOBALS['BE_USER'];
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $draft = $this->writer->create(self::TABLE, 1);
        $draft->context->run(function () use ($draft, $editor): void {
            try {
                $draft->context->run(static function () use ($editor): void {
                    $GLOBALS['BE_USER'] = $editor;
                    throw new \RuntimeException('Nested failure', 1792320003);
                });
            } catch (\RuntimeException $exception) {
                self::assertSame(1792320003, $exception->getCode());
                self::assertSame($draft->context->user, $GLOBALS['BE_USER']);
            }
        });
        self::assertSame($editor, $GLOBALS['BE_USER']);
    }

    #[Test]
    #[DataProvider('localizedRelationContexts')]
    public function localizedDynamicSelectReceivesCurrentRowPidLanguageAndSite(int $workspace, int $language): void
    {
        $parent = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        if ($workspace) {
            $GLOBALS['BE_USER']->workspace = 1;
            $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        }
        $editable = $language ? $this->writer->localize($parent, $language) : $parent;
        $draft = $this->writer->edit($editable);
        $this->writer->move($parent, RecordPosition::onPage(2));
        Fixtures\SelectItemsHook::$contexts = [];
        $saved = $this->writer->save($draft->set('placement', 20 + $language));
        self::assertSame(2, $saved->getPid());
        self::assertSame($language, $this->writer->identity($saved)->languageId);
        self::assertNotEmpty(Fixtures\SelectItemsHook::$contexts);
        foreach (Fixtures\SelectItemsHook::$contexts as $parameters) {
            self::assertSame(2, (int)$parameters['pid']);
            self::assertSame(2, $parameters['effectivePid']);
            self::assertSame($language, (int)$parameters['language']);
            self::assertSame('record-write', $parameters['site']);
        }
    }

    #[Test]
    public function inlineReorderingChangesOnlyOrderAndKeepsAllChildren(): void
    {
        $third = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Third'));
        $second = new RecordReference(self::TABLE, 2);
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $draft->relations('children')->setMembers([$second, $third]);
        $parent = $this->writer->save($draft);
        $draft = $this->writer->edit($parent);
        $draft->relations('children')->reorder([$third, $second]);
        $saved = $this->writer->save($draft);
        self::assertSame([$third->getUid(), 2], array_map(static fn(RecordReference $ref): int => $ref->uid, $this->writer->edit($saved)->relations('children')->toArray()));
    }

    #[Test]
    public function aDraftCannotExposeItsSnapshotInAnotherFrontendRequest(): void
    {
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $this->expectException(InvalidWriteContextException::class);
        $draft->get('title');
    }

    public static function idempotentIntent(): iterable
    {
        yield 'add initially present but removed later' => [true];
        yield 'remove initially absent but added later' => [false];
    }

    #[Test]
    #[DataProvider('idempotentIntent')]
    public function idempotentRelationOperationsRetainTheirIntent(bool $add): void
    {
        $ref = new RecordReference(self::TABLE, 2);
        if ($add) {
            $this->writer->save($this->writer->editByUid(self::TABLE, 1)->setRelation('related', $ref));
        }
        $a = $this->writer->editByUid(self::TABLE, 1);
        $set = $a->relations('related');
        $add ? $set->add($ref) : $set->remove($ref);
        $b = $this->writer->editByUid(self::TABLE, 1);
        $other = $b->relations('related');
        $add ? $other->remove($ref) : $other->add($ref);
        $this->writer->save($b);
        $saved = $this->writer->save($a);
        self::assertSame($add ? [2] : [], array_map(static fn(RecordReference $ref): int => $ref->uid, $this->writer->edit($saved)->relations('related')->toArray()));
    }

    public static function maintenanceModes(): iterable
    {
        yield 'closed maintenance' => [1, false];
        yield 'CLI allowed maintenance' => [2, true];
    }

    #[Test]
    #[DataProvider('maintenanceModes')]
    public function firstUseProvisioningPreservesNativeMaintenanceRestrictions(int $mode, bool $allowed): void
    {
        $editor = $GLOBALS['BE_USER'];
        $GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'] = $mode;
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        try {
            $record = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Maintenance'));
            self::assertTrue($allowed);
            self::assertSame('Maintenance', $record->get('title'));
            self::assertCount(1, $this->cliRows());
        } catch (InvalidWriteContextException $exception) {
            if ($allowed) {
                throw $exception;
            }
            self::assertSame([], $this->cliRows());
        }
        self::assertSame($editor, $GLOBALS['BE_USER']);
    }

    public static function staleRelationContexts(): iterable
    {
        yield from self::localizedRelationContexts();
        yield 'localized inline' => [0, 1, 'children'];
    }

    #[Test]
    public function firstUseAndAfterWriteFailurePreserveExistingVisitorAndEditorState(): void
    {
        $editor = $GLOBALS['BE_USER'];
        $editor->workspace = 1;
        $context = $this->get(Context::class);
        $workspace = new WorkspaceAspect(1);
        $context->setAspect('workspace', $workspace);
        $visitor = new FrontendUserAuthentication();
        $visitor->user = ['uid' => 456, 'username' => 'Visitor'];
        $visitorAspect = new UserAspect($visitor);
        $context->setAspect('frontend.user', $visitorAspect);
        $request = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)->withAttribute('frontend.user', $visitor);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $before = [$editor->user, $editor->groupData, $GLOBALS['LANG'] ?? null, $context->getAspect('backend.user'), $context->getAspect('language')];
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\FailAfterWriteHook::class;
        $draft = $this->writer->create(self::TABLE, 1)->set('title', 'Fail after persistence');
        try {
            $this->writer->save($draft);
            self::fail('The post-write hook must report an uncertain outcome.');
        } catch (DataHandlerExecutionException $exception) {
            self::assertSame(1792305061, $exception->getPrevious()->getCode());
            self::assertCount(1, $this->cliRows());
            self::assertSame($editor, $GLOBALS['BE_USER']);
            self::assertSame($request, $GLOBALS['TYPO3_REQUEST']);
            self::assertSame($visitorAspect, $context->getAspect('frontend.user'));
            self::assertSame($workspace, $context->getAspect('workspace'));
            self::assertSame(1, $editor->workspace);
            self::assertSame(['uid' => 456, 'username' => 'Visitor'], $visitor->user);
            self::assertSame($before, [$editor->user, $editor->groupData, $GLOBALS['LANG'] ?? null, $context->getAspect('backend.user'), $context->getAspect('language')]);
            $identity = $exception->diagnostics->forDraft($draft)->identity;
            self::assertNotNull($identity);
            $historyUser = $this->getConnectionPool()->getConnectionForTable('sys_history')->select(['userid'], 'sys_history', ['tablename' => self::TABLE, 'recuid' => $identity->physicalUid])->fetchOne();
            self::assertSame((int)$this->cliRows()[0]['uid'], (int)$historyUser);
        }
    }
}
