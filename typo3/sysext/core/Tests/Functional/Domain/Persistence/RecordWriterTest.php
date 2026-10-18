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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Domain\Persistence\Exception\DataHandlerExecutionException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordWriterTest extends FunctionalTestCase
{
    private const TABLE = 'tx_recordwrite_item';
    protected array $coreExtensionsToLoad = ['workspaces'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];
    private RecordWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_REQUEST'] = (new \TYPO3\CMS\Core\Http\ServerRequest())->withAttribute('applicationType', \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $this->writer = $this->get(RecordWriter::class);
        $this->get(\TYPO3\CMS\Core\Configuration\SiteWriter::class)->write('record-write', [
            'rootPageId' => 1, 'base' => 'https://example.org/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
            ],
        ]);
    }

    #[Test]
    public function createAndUpdateReturnFreshRecordsWithoutChangingOriginals(): void
    {
        $draft = $this->writer->create(self::TABLE, 1)->setValues(['title' => 'Created', 'body' => null, 'hidden' => false]);
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        $created = $this->writer->save($draft);
        self::assertGreaterThan(2, $created->getUid());
        self::assertSame('Created', $created->get('title'));
        self::assertNull($created->get('body'));
        $edit = $this->writer->edit($created)->set('title', 'Changed');
        $saved = $this->writer->save($edit);
        self::assertSame('Changed', $saved->get('title'));
        self::assertSame('Created', $created->get('title'));
        self::assertNotSame($created, $saved);
        self::assertNull($saved->get('body'));
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function setRelationCanBeRefinedThroughTheRelationSet(): void
    {
        $related = $this->writer->save($this->writer->editByUid(self::TABLE, 2));
        $draft = $this->writer->editByUid(self::TABLE, 1)->setRelation('related', $related);
        $draft->relations('related')->add($related);
        $saved = $this->writer->save($draft);
        self::assertSame([2], array_map(static fn($reference): int => $reference->uid, $this->writer->edit($saved)->relations('related')->toArray()));
    }

    #[Test]
    public function updateOnlyPassesPendingChangesAndDiscardRestoresOriginalValue(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $draft->set('title', 'First')->set('title', 'Second')->set('body', null)->discard('body');
        self::assertSame('Original body', $draft->get('body'));
        $saved = $this->writer->save($draft);
        self::assertSame('Second', $saved->get('title'));
        self::assertSame('Original body', $saved->get('body'));
    }

    #[Test]
    public function noOpDoesNotCreateAWorkspaceVersion(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $record = $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set('title', 'Original'));
        self::assertSame(1, $record->getUid());
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
    }

    #[Test]
    public function updateInWorkspaceDoesNotChangeLiveAndUsesPhysicalVersionUid(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $record = $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set('title', 'Draft title'));
        self::assertNotSame(1, $record->getUid());
        self::assertSame(1, (int)$record->getRawRecord()->get('t3ver_oid'));
        self::assertSame('Original', $this->row(1)['title']);
        $again = $this->writer->save($this->writer->edit($record)->set('title', 'Second edit'));
        self::assertSame($record->getUid(), $again->getUid());
        self::assertSame('Second edit', $again->get('title'));
    }

    #[Test]
    public function changedWorkspaceIsRejectedBeforeWriting(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Unsafe');
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    public static function invalidValues(): iterable
    {
        yield ['uid', 99];
        yield ['pid', 2];
        yield ['tstamp', 123];
        yield ['deleted', 1];
        yield ['sys_language_uid', 1];
        yield ['t3ver_wsid', 1];
        yield ['does_not_exist', 'value'];
        yield ['readonly_value', 'value'];
        yield ['title', null];
        yield ['amount', 'not an integer'];
        yield ['hidden', new \stdClass()];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function invalidFieldsAndValuesAreRejected(string $field, mixed $value): void
    {
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set($field, $value));
    }

    #[Test]
    public function missingAuthenticationIsRejected(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->create(self::TABLE, 1);
    }

    #[Test]
    public function unknownTableIsRejected(): void
    {
        $this->expectException(\TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException::class);
        $this->writer->create('not_a_table', 1);
    }

    #[Test]
    public function dataHandlerDenialConsumesDraftAndReportsPotentialPartialOutcome(): void
    {
        $GLOBALS['BE_USER']->user['admin'] = 0;
        $GLOBALS['BE_USER']->groupData['tables_modify'] = '';
        $draft = $this->writer->create(self::TABLE, 1)->set('title', 'Denied');
        try {
            $this->writer->save($draft);
            self::fail('Missing DataHandler failure');
        } catch (DataHandlerExecutionException $exception) {
            self::assertNotEmpty($exception->errors);
        }
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function unchangedDraftDoesNotNeedWritePermissionOrExecuteDataHandler(): void
    {
        $GLOBALS['BE_USER']->user['admin'] = 0;
        $GLOBALS['BE_USER']->groupData['tables_modify'] = '';
        $draft = $this->writer->editByUid(self::TABLE, 1);
        self::assertSame('Original', $this->writer->save($draft)->get('title'));
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable('sys_history')->count('*', 'sys_history', []));
    }

    #[Test]
    public function moveCopyDeleteAndRestoreUseDataHandlerCommands(): void
    {
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $copy = $this->writer->copy($original, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::onPage(2));
        self::assertNotSame(1, $copy->getUid());
        self::assertSame(2, $copy->getPid());
        $moved = $this->writer->move($original, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::onPage(2));
        self::assertSame(2, $moved->getPid());
        $after = $this->writer->move($copy, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::after($moved));
        self::assertGreaterThan((int)$moved->getRawRecord()->get('sorting'), (int)$after->getRawRecord()->get('sorting'));
        $this->writer->delete($moved);
        self::assertSame(1, (int)$this->row(1)['deleted']);
        $restored = $this->writer->restore(self::TABLE, 1);
        self::assertSame(0, (int)$restored->getRawRecord()->get('deleted'));
    }

    #[Test]
    #[DataProvider('positionContexts')]
    public function relativeMoveAndCopyReadTheCurrentOrdering(bool $workspace, bool $translated): void
    {
        $source = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $preceding = $this->writer->save($this->writer->editByUid(self::TABLE, 2));
        if ($translated) {
            $source = $this->writer->copyToLanguage($source, 1);
            $preceding = $this->writer->copyToLanguage($preceding, 1);
        }
        if ($workspace) {
            $GLOBALS['BE_USER']->workspace = 1;
            $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        }
        $position = \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::after($preceding);
        $moved = $this->writer->move($source, $position);
        self::assertNotNull($moved);
        self::assertGreaterThan((int)$preceding->getRawRecord()->get('sorting'), (int)$moved->getRawRecord()->get('sorting'));
        self::assertSame((int)$workspace, $this->writer->identity($moved)->workspaceId);
        self::assertSame((int)$translated, $this->writer->identity($moved)->languageId);
        $copy = $this->writer->copy($source, $position);
        self::assertNotNull($copy);
        self::assertGreaterThan((int)$preceding->getRawRecord()->get('sorting'), (int)$copy->getRawRecord()->get('sorting'));
        self::assertSame((int)$workspace, $this->writer->identity($copy)->workspaceId);
        self::assertSame((int)$translated, $this->writer->identity($copy)->languageId);
    }

    public static function positionContexts(): iterable
    {
        yield 'Live' => [false, false];
        yield 'workspace' => [true, false];
        yield 'independent translation' => [false, true];
        yield 'workspace translation' => [true, true];
    }

    #[Test]
    public function localizationUpdatesOnlyTheTranslationAndRejectsDuplicates(): void
    {
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $translation = $this->writer->localize($original, 1);
        self::assertSame(1, (int)$translation->getRawRecord()->get('sys_language_uid'));
        self::assertSame(1, (int)$translation->getRawRecord()->get('l10n_parent'));
        $saved = $this->writer->save($this->writer->edit($translation)->set('title', 'Übersetzt'));
        self::assertSame($translation->getUid(), $saved->getUid());
        self::assertSame('Original', $this->row(1)['title']);
        self::assertSame('Übersetzt', $saved->get('title'));
        $this->expectException(RecordPersistenceException::class);
        $this->writer->localize($original, 1);
    }

    #[Test]
    public function translationInWorkspaceDoesNotModifyLiveTranslationOrDefaultLanguage(): void
    {
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $translation = $this->writer->localize($original, 1);
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $saved = $this->writer->save($this->writer->edit($translation)->set('title', 'Workspace translation'));
        self::assertNotSame($translation->getUid(), $saved->getUid());
        self::assertSame($translation->getUid(), (int)$saved->getRawRecord()->get('t3ver_oid'));
        self::assertSame(1, (int)$saved->getRawRecord()->get('sys_language_uid'));
        self::assertSame('Original', $this->row($translation->getUid())['title']);
        self::assertSame('Original', $this->row(1)['title']);
        $this->writer->discardWorkspaceVersion($saved);
        self::assertSame('Original', $this->writer->save($this->writer->editByUid(self::TABLE, $translation->getUid()))->get('title'));
    }

    #[Test]
    public function localizeAndDeleteInWorkspaceLeaveLiveUntouched(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $translation = $this->writer->localize($original, 1);
        self::assertSame(1, (int)$translation->getRawRecord()->get('t3ver_wsid'));
        self::assertSame(1, (int)$translation->getRawRecord()->get('sys_language_uid'));
        $this->writer->delete($original);
        self::assertSame(0, (int)$this->row(1)['deleted']);
    }

    #[Test]
    public function copyToLanguageCreatesAnIndependentTranslation(): void
    {
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $copy = $this->writer->copyToLanguage($original, 1);
        self::assertSame(1, (int)$copy->getRawRecord()->get('sys_language_uid'));
        self::assertSame(0, (int)$copy->getRawRecord()->get('l10n_parent'));
    }

    #[Test]
    public function languageFallbackRecordCannotBeEdited(): void
    {
        $row = $this->row(1);
        $row['_REQUESTED_OVERLAY_LANGUAGE'] = 1;
        $record = $this->get(RecordFactory::class)->createFromDatabaseRow(self::TABLE, $row);
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->edit($record);
    }

    #[Test]
    public function groupAndMmRelationsSupportAddRemoveReplaceClearAndOrdering(): void
    {
        $other = $this->writer->save($this->writer->editByUid(self::TABLE, 2)->set('hidden', true));
        $third = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Third'));
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $draft->setRelation('reference', $other);
        $draft->relations('related')->setMembers([$other, $third]);
        $saved = $this->writer->save($draft);
        self::assertSame('2', (string)$saved->getRawRecord()->get('reference'));
        $edit = $this->writer->edit($saved);
        self::assertCount(2, $edit->relations('related')->toArray(), 'Hidden relation members must be preserved');
        $edit->relations('related')->reorder([$third, $other]);
        $saved = $this->writer->save($edit);
        self::assertSame([$third->getUid(), 2], $this->relationIds($saved->getUid()));
        $edit = $this->writer->edit($saved);
        $edit->relations('related')->remove($other)->add($third);
        $saved = $this->writer->save($edit);
        self::assertSame([$third->getUid()], $this->relationIds($saved->getUid()));
        $edit = $this->writer->edit($saved);
        $edit->relations('related')->clear();
        $edit->setRelation('reference', null);
        $saved = $this->writer->save($edit);
        self::assertSame([], $this->relationIds($saved->getUid()));
        self::assertEmpty($saved->getRawRecord()->get('reference'));
    }

    #[Test]
    public function existingInlineChildrenCanBeAttachedAndDetachedWithoutDeletingThem(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $draft->relations('children')->add(new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2));
        $saved = $this->writer->save($draft);
        self::assertSame(1, (int)$this->row(2)['parent']);
        $draft = $this->writer->edit($saved);
        self::assertCount(1, $draft->relations('children')->toArray());
        $draft->relations('children')->clear();
        $this->writer->save($draft);
        self::assertSame(0, (int)$this->row(2)['parent']);
        self::assertSame(0, (int)$this->row(2)['deleted']);
    }

    #[Test]
    public function workspaceMmUpdatesLeaveLiveRelationsUntouched(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $draft->relations('related')->add(new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2));
        $this->writer->save($draft);
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $draft = $this->writer->editByUid(self::TABLE, 1);
        self::assertCount(1, $draft->relations('related')->toArray());
        $draft->relations('related')->clear();
        $saved = $this->writer->save($draft);
        self::assertNotSame(1, $saved->getUid());
        self::assertSame([2], $this->relationIds(1));
        self::assertSame([], $this->relationIds($saved->getUid()));
    }

    #[Test]
    public function batchCreatesRelatedRecordsUsingDataHandlerNewIdentifiers(): void
    {
        $batch = $this->writer->batch();
        $first = $batch->create(self::TABLE, 1)->set('title', 'Parent');
        $second = $batch->create(self::TABLE, 1)->set('title', 'Child');
        $first->setRelation('children', $second);
        $first->setRelation('related', $second);
        $first->setRelation('reference', $second);
        $update = $batch->editByUid(self::TABLE, 1)->set('title', 'Batch update');
        $result = $batch->execute();
        $parent = $result->recordFor($first);
        $child = $result->recordFor($second);
        self::assertSame($parent->getUid(), (int)$child->getRawRecord()->get('parent'));
        self::assertSame([$child->getUid()], $this->relationIds($parent->getUid()));
        self::assertSame((string)$child->getUid(), (string)$parent->getRawRecord()->get('reference'));
        self::assertSame('Batch update', $result->recordFor($update)->get('title'));
        $this->expectException(InvalidWriteContextException::class);
        $batch->execute();
    }

    #[Test]
    public function batchCanEditExistingRecordsThroughRecordObjects(): void
    {
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $batch = $this->writer->batch();
        $draft = $batch->edit($original)->set('title', 'Through record');
        self::assertSame('Through record', $batch->execute()->recordFor($draft)->get('title'));
    }

    #[Test]
    public function invalidBatchDoesNotWriteAnyRecord(): void
    {
        $batch = $this->writer->batch();
        $first = $batch->create(self::TABLE, 1)->set('title', 'Valid');
        $second = $batch->create(self::TABLE, 1)->set('uid', 999);
        try {
            $batch->execute();
            self::fail('Expected validation failure');
        } catch (InvalidRecordValueException) {
            self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        }
        $second->discard('uid')->set('title', 'Fixed');
        self::assertSame('Valid', $batch->execute()->recordFor($first)->get('title'));
    }

    #[Test]
    public function conflictingBatchDraftsAreRejected(): void
    {
        $batch = $this->writer->batch();
        $batch->editByUid(self::TABLE, 1)->set('title', 'First');
        $batch->editByUid(self::TABLE, 1)->set('title', 'Second');
        $this->expectException(InvalidRecordValueException::class);
        $batch->execute();
    }

    #[Test]
    public function attemptedBatchCannotBeRetriedAfterAPartialFailure(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\FailAfterWriteHook::class;
        $batch = $this->writer->batch();
        $first = $batch->create(self::TABLE, 1)->set('title', 'First persisted');
        $batch->create(self::TABLE, 1)->set('title', 'Fail after persistence');
        try {
            $batch->execute();
            self::fail('Expected post-write hook failure');
        } catch (DataHandlerExecutionException $exception) {
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            self::assertSame(\TYPO3\CMS\Core\Domain\Persistence\RecordWriteOutcome::Unknown, $exception->diagnostics->forDraft($first)->outcome);
            self::assertNotNull($exception->diagnostics->forDraft($first)->uid);
            self::assertSame(4, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        }
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($first);
    }

    #[Test]
    public function lostResultMappingIsDistinguishedFromExecutionFailureAndPreventsRetry(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\FailAfterWriteHook::class;
        $draft = $this->writer->create(self::TABLE, 1)->set('title', 'Lose result mapping');
        self::assertNull($this->writer->save($draft));
        self::assertSame(3, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function inlineMatchDiscriminatorsAreRejectedBeforeChangingOwnership(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->setRelation('matched_children', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2));
        $this->expectException(UnsupportedRecordWriteException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function newRelationsOutsideTheirBatchAreRejectedBeforeWriting(): void
    {
        $parent = $this->writer->create(self::TABLE, 1);
        $child = $this->writer->create(self::TABLE, 1);
        $parent->setRelation('related', $child);
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($parent);
    }

    #[Test]
    public function typedValuesArePersistedAndResolvedThroughTheExistingRecordApi(): void
    {
        $date = new \DateTimeImmutable('2026-01-12 12:00:00 UTC');
        $link = new \TYPO3\CMS\Core\LinkHandling\TypolinkParameter('https://example.org/', '_blank', '', 'A title');
        $draft = $this->writer->editByUid(self::TABLE, 1)->setValues([
            'starttime' => $date, 'payload' => ['answer' => 42], 'amount' => 17,
            'flags' => 3, 'email' => 'writer@example.org', 'link' => $link,
        ]);
        $saved = $this->writer->save($draft);
        self::assertSame($date->getTimestamp(), $saved->getRawRecord()->get('starttime'));
        self::assertSame(['answer' => 42], $saved->get('payload'));
        self::assertSame(17, $saved->get('amount'));
        self::assertSame(3, $saved->get('flags'));
        self::assertSame('writer@example.org', $saved->get('email'));
        self::assertEquals($link, $saved->get('link'));
    }

    #[Test]
    public function changingContentTypeUsesTheResultingSchema(): void
    {
        $record = $this->writer->save($this->writer->create('tt_content', 1)->setValues(['CType' => 'header', 'header' => 'Heading']));
        $changed = $this->writer->save($this->writer->edit($record)->setValues(['CType' => 'text', 'bodytext' => 'A body']));
        self::assertSame('text', $changed->getRecordType());
        self::assertSame('A body', $changed->get('bodytext'));
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($this->writer->edit($changed)->setValues(['CType' => 'header', 'bodytext' => 'Disallowed for header']));
    }

    #[Test]
    public function missingRecordsAreRejected(): void
    {
        $this->expectException(\TYPO3\CMS\Core\Domain\Persistence\Exception\RecordNotFoundException::class);
        $this->writer->editByUid(self::TABLE, 999999);
    }

    #[Test]
    public function recordsWithoutRawIdentityAreRejected(): void
    {
        $record = $this->createMock(\TYPO3\CMS\Core\Domain\RecordInterface::class);
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->edit($record);
    }

    #[Test]
    public function languageContextChangesAreDetectedBeforeWriting(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Unsafe');
        $this->get(Context::class)->setAspect('language', new \TYPO3\CMS\Core\Context\LanguageAspect(1));
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function excludedFieldsCannotBeWrittenByAnUnprivilegedBackendUser(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('restricted', 'Denied');
        $GLOBALS['BE_USER']->user['admin'] = 0;
        $GLOBALS['BE_USER']->groupData['non_exclude_fields'] = '';
        $this->expectException(\TYPO3\CMS\Core\Domain\Persistence\Exception\RecordAccessDeniedException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function synchronizedFieldsCannotBeChangedOnTranslations(): void
    {
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $translation = $this->writer->localize($original, 1);
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($this->writer->edit($translation)->set('synchronized', 'Denied'));
    }

    #[Test]
    public function workspaceRecordCannotBeWrittenFromLive(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $version = $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set('title', 'Version'));
        $GLOBALS['BE_USER']->workspace = 0;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->edit($version);
    }

    #[Test]
    public function workspaceMoveAndCopyDoNotChangeLive(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $moved = $this->writer->move($original, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::onPage(2));
        self::assertSame(2, $moved->getPid());
        self::assertSame(1, (int)$this->row(1)['pid']);
        $saved = $this->writer->save($this->writer->edit($moved)->set('title', 'Moved version'));
        self::assertSame(2, $saved->getPid());
        $copy = $this->writer->copy($saved, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::onPage(1));
        self::assertSame(1, (int)$copy->getRawRecord()->get('t3ver_wsid'));
        self::assertSame('Original', $this->row(1)['title']);
    }

    #[Test]
    public function newRelationSetsCanReferenceNewDraftsInTheirBatch(): void
    {
        $batch = $this->writer->batch();
        $first = $batch->create(self::TABLE, 1)->set('title', 'First');
        $second = $batch->create(self::TABLE, 1)->set('title', 'Second');
        $first->relations('related')->add($second);
        $result = $batch->execute();
        self::assertSame([$result->recordFor($second)->getUid()], $this->relationIds($result->recordFor($first)->getUid()));
    }

    #[Test]
    public function cyclicNewInlineGraphsAreRejectedBeforePersistence(): void
    {
        $batch = $this->writer->batch();
        $first = $batch->create(self::TABLE, 1);
        $second = $batch->create(self::TABLE, 1);
        $first->setRelation('children', $second);
        $second->setRelation('children', $first);
        $this->expectException(InvalidRecordValueException::class);
        $batch->execute();
    }

    #[Test]
    public function inlineReparentingAndAncestorCyclesAreRejected(): void
    {
        $child = new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2);
        $parent = $this->writer->editByUid(self::TABLE, 1)->setRelation('children', $child);
        $this->writer->save($parent);
        $cycle = $this->writer->editByUid(self::TABLE, 2)->setRelation('children', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 1));
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($cycle);
    }

    #[Test]
    public function recordForRejectsDraftsOutsideTheResult(): void
    {
        $result = $this->writer->batch()->execute();
        $this->expectException(InvalidRecordValueException::class);
        $result->recordFor($this->writer->create(self::TABLE, 1));
    }

    #[Test]
    public function coreCategoryRelationsUseTheirReverseMmConfigurationInLiveAndWorkspace(): void
    {
        $category = $this->writer->save($this->writer->create('sys_category', 0)->set('title', 'Category'));
        $content = $this->writer->save($this->writer->create('tt_content', 1)->set('header', 'Content'));
        $draft = $this->writer->edit($content);
        $draft->relations('categories')->add($category);
        $content = $this->writer->save($draft);
        self::assertCount(1, $this->writer->edit($content)->relations('categories')->toArray());
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $draft = $this->writer->edit($content);
        $draft->relations('categories')->clear();
        $version = $this->writer->save($draft);
        self::assertCount(0, $this->writer->edit($version)->relations('categories')->toArray());
        $GLOBALS['BE_USER']->workspace = 0;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        self::assertCount(1, $this->writer->edit($content)->relations('categories')->toArray());
    }

    #[Test]
    public function anInlineChildCannotHaveTwoNewParentsInTheSameBatch(): void
    {
        $batch = $this->writer->batch();
        $one = $batch->create(self::TABLE, 1);
        $two = $batch->create(self::TABLE, 1);
        $child = $batch->create(self::TABLE, 1);
        $one->setRelation('children', $child);
        $two->setRelation('children', $child);
        $this->expectException(InvalidRecordValueException::class);
        $batch->execute();
    }

    #[Test]
    public function workspaceInlineDetachmentOfLiveChildrenFailsBeforePersistence(): void
    {
        $this->writer->save($this->writer->editByUid(self::TABLE, 1)->setRelation('children', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2)));
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $draft->relations('children')->clear();
        try {
            $this->writer->save($draft);
            self::fail('Workspace detachment of a Live child depends on an independent Core fix.');
        } catch (\TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException) {
            self::assertSame(1, (int)$this->row(2)['parent']);
            self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
            self::assertTrue($draft->hasChanges());
        }
    }

    public static function inconsistentWorkspaceInlineParents(): iterable
    {
        yield 'detached child still reported' => [101, 1792330001];
        yield 'attached version omitted' => [102, 1792330002];
    }

    #[Test]
    #[DataProvider('inconsistentWorkspaceInlineParents')]
    public function inconsistentWorkspaceInlineMembershipIsRejectedInsteadOfReturningIncorrectRelations(int $parent, int $code): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/workspace-inline-ownership.csv');
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $this->expectException(\TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException::class);
        $this->expectExceptionCode($code);
        $this->writer->editByUid(self::TABLE, $parent)->relations('children');
    }

    #[Test]
    public function attachingAnExistingVersionedLiveChildFailsBeforePersistence(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $this->writer->save($this->writer->editByUid(self::TABLE, 2)->set('title', 'Workspace child'));
        $draft = $this->writer->editByUid(self::TABLE, 1)->setRelation('children', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2));
        try {
            $this->writer->save($draft);
            self::fail('Attaching an existing Live child would produce inconsistent Core relation reads.');
        } catch (\TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException) {
            self::assertSame(0, (int)$this->row(2)['parent']);
            self::assertSame(0, (int)$this->row(1)['t3ver_wsid']);
            self::assertSame(3, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        }
    }

    #[Test]
    public function newInlineChildrenCanBeCreatedInAWorkspaceBatch(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $batch = $this->writer->batch();
        $parent = $batch->editByUid(self::TABLE, 1);
        $child = $batch->create(self::TABLE, 1)->set('title', 'New workspace child');
        $parent->relations('children')->add($child);
        $result = $batch->execute();
        $childRecord = $result->recordFor($child);
        self::assertSame(1, (int)$childRecord->getRawRecord()->get('t3ver_wsid'));
        self::assertSame(1, (int)$childRecord->getRawRecord()->get('parent'));
        self::assertSame(0, (int)$this->row(1)['children']);
        self::assertCount(1, $this->writer->edit($result->recordFor($parent))->relations('children')->toArray());
    }

    #[Test]
    public function workspaceOwnedInlineChildrenCanBeReorderedAndDetached(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $batch = $this->writer->batch();
        $parent = $batch->editByUid(self::TABLE, 1);
        $first = $batch->create(self::TABLE, 1)->set('title', 'First workspace child');
        $second = $batch->create(self::TABLE, 1)->set('title', 'Second workspace child');
        $parent->relations('children')->setMembers([$first, $second]);
        $result = $batch->execute();
        $parentRecord = $result->recordFor($parent);
        $firstRecord = $result->recordFor($first);
        $secondRecord = $result->recordFor($second);
        $draft = $this->writer->edit($parentRecord);
        $draft->relations('children')->reorder([$secondRecord, $firstRecord]);
        $saved = $this->writer->save($draft);
        self::assertSame([$secondRecord->getUid(), $firstRecord->getUid()], array_map(static fn($reference): int => $reference->uid, $this->writer->edit($saved)->get('children')));
        $draft = $this->writer->edit($saved);
        $draft->relations('children')->remove($firstRecord);
        $saved = $this->writer->save($draft);
        self::assertSame([$secondRecord->getUid()], array_map(static fn($reference): int => $reference->uid, $this->writer->edit($saved)->get('children')));
        self::assertSame(0, (int)$this->row($firstRecord->getUid())['parent']);
        self::assertSame(1, (int)$this->row($firstRecord->getUid())['t3ver_wsid']);
        self::assertSame(0, (int)$this->row(1)['children']);
    }

    #[Test]
    public function existingInlineMembersCanBeRetainedWhenParentIsVersionedAndSavedAgain(): void
    {
        $this->writer->save($this->writer->editByUid(self::TABLE, 1)->setRelation('children', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2)));
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Version parent');
        $draft->relations('children')->setMembers([new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2)]);
        $version = $this->writer->save($draft);
        $draft = $this->writer->edit($version);
        $draft->relations('children')->setMembers([new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2)]);
        $this->writer->save($draft);
        self::assertSame(1, (int)$this->row(2)['parent']);
        self::assertSame(0, (int)$this->row(2)['t3ver_wsid']);
        self::assertCount(1, $this->writer->edit($version)->relations('children')->toArray());
    }

    #[Test]
    public function pendingFieldsUseTheCurrentTypeWhenAnotherWriteChangedIt(): void
    {
        $record = $this->writer->save($this->writer->create('tt_content', 1)->set('CType', 'text'));
        $draft = $this->writer->edit($record)->set('bodytext', 'Stale type');
        $this->writer->save($this->writer->edit($record)->set('CType', 'header'));
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function workspacePageVersionsAreResolvedToLogicalStoragePageIds(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $pageVersion = $this->writer->save($this->writer->editByUid('pages', 1)->set('title', 'Workspace page'));
        self::assertNotSame(1, $pageVersion->getUid());
        $created = $this->writer->save($this->writer->create(self::TABLE, $pageVersion->getUid())->set('title', 'On workspace page'));
        self::assertSame(1, $created->getPid());
        $source = $this->writer->save($this->writer->editByUid(self::TABLE, 2));
        $moved = $this->writer->move($source, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::onPage($pageVersion->getUid()));
        self::assertSame(1, $moved->getPid());
        $copy = $this->writer->copy($moved, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::onPage($pageVersion->getUid()));
        self::assertSame(1, $copy->getPid());
    }

    #[Test]
    public function translatedPagePositionsAreRejectedBeforeCreatingRecords(): void
    {
        $page = $this->writer->save($this->writer->editByUid('pages', 1));
        $translation = $this->writer->localize($page, 1);
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->create(self::TABLE, $translation->getUid());
    }

    #[Test]
    public function dataHandlerResolvesPageTsDefaultTypeDuringExecution(): void
    {
        $this->writer->save($this->writer->editByUid('pages', 1)->set('TSconfig', 'TCAdefaults.tt_content.CType = header'));
        $record = $this->writer->save($this->writer->create('tt_content', 1)->set('header', 'Page default header'));
        self::assertSame('header', $record->getRecordType());

    }

    #[Test]
    public function dataHandlerResolvesUserTsDefaultTypeDuringExecution(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/type-default.csv');
        $this->setUpBackendUser(3);
        $record = $this->writer->save($this->writer->create('tt_content', 1)->set('header', 'Default header'));
        self::assertSame('header', $record->getRecordType());

    }

    #[Test]
    public function defaultLanguageSynchronizationCannotBeOverriddenByChangingTheValue(): void
    {
        $record = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $translation = $this->writer->localize($record, 1);
        $this->expectException(InvalidRecordValueException::class);
        $this->writer->save($this->writer->edit($translation)->set('syncable', 'Unsafe override'));
    }

    #[Test]
    public function inlineChildPagePermissionsAreCheckedBeforeForeignFieldChanges(): void
    {
        $child = $this->writer->save($this->writer->create(self::TABLE, 3)->set('title', 'Restricted child'));
        $draft = $this->writer->editByUid(self::TABLE, 1)->setRelation('children', $child);
        $GLOBALS['BE_USER']->user['admin'] = 0;
        $GLOBALS['BE_USER']->groupData['tables_modify'] = self::TABLE;
        $GLOBALS['BE_USER']->groupData['webmounts'] = '1';
        $this->expectException(\TYPO3\CMS\Core\Domain\Persistence\Exception\RecordAccessDeniedException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function looseLiveInlineChildrenAreNotSilentlyModifiedFromAWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $draft = $this->writer->editByUid(self::TABLE, 1)->setRelation('children', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2));
        $this->expectException(\TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function realCombinedLanguageAndWorkspaceOverlayTargetsTheTranslationVersion(): void
    {
        $original = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $translation = $this->writer->localize($original, 1);
        $GLOBALS['BE_USER']->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $version = $this->writer->save($this->writer->edit($translation)->set('title', 'Versioned translation'));
        $row = $this->get(\TYPO3\CMS\Core\Domain\Repository\PageRepository::class)->getLanguageOverlay(self::TABLE, $this->row(1), new \TYPO3\CMS\Core\Context\LanguageAspect(1));
        self::assertNotNull($row);
        $record = $this->get(RecordFactory::class)->createResolvedRecordFromDatabaseRow(self::TABLE, $row);
        self::assertSame('Versioned translation', $record->get('title'));
        $saved = $this->writer->save($this->writer->edit($record)->set('title', 'Edited overlay'));
        self::assertSame($version->getUid(), $saved->getUid());
        self::assertSame('Original', $this->row(1)['title']);
        self::assertSame('Original', $this->row($translation->getUid())['title']);
    }

    #[Test]
    public function batchPreflightIdentifiesInvalidRowsWithoutConsumingOrPersistingDrafts(): void
    {
        $batch = $this->writer->batch();
        $valid = $batch->create(self::TABLE, 1)->set('title', 'Valid row');
        $invalid = $batch->create(self::TABLE, 1)->set('email', 'invalid');
        $validation = $batch->validate();
        self::assertFalse($validation->isValid());
        self::assertSame([], $validation->forDraft($valid)->errors);
        self::assertNotEmpty($validation->forDraft($invalid)->errors);
        self::assertSame(\TYPO3\CMS\Core\Domain\Persistence\RecordWriteOutcome::NotAttempted, $validation->forDraft($invalid)->outcome);
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        $invalid->set('email', 'corrected@example.org');
        self::assertTrue($batch->validate()->isValid());
        $result = $batch->execute();
        self::assertSame(\TYPO3\CMS\Core\Domain\Persistence\RecordWriteOutcome::Unknown, $result->diagnostics->forDraft($valid)->outcome);
        self::assertSame('corrected@example.org', $result->recordFor($invalid)->get('email'));
    }

    #[Test]
    public function batchPreflightIncludesConflictsBetweenOtherwiseValidDrafts(): void
    {
        $batch = $this->writer->batch();
        $one = $batch->editByUid(self::TABLE, 1)->set('title', 'First');
        $two = $batch->editByUid(self::TABLE, 1)->set('title', 'Conflicting');
        $validation = $batch->validate();
        self::assertFalse($validation->isValid());
        self::assertNotEmpty($validation->forDraft($one)->errors);
        self::assertNotEmpty($validation->forDraft($two)->errors);
        $batch->remove($two);
        self::assertTrue($batch->validate()->isValid());
        self::assertSame('First', $batch->execute()->recordFor($one)->get('title'));
    }

    #[Test]
    public function batchPreflightSupportsNewReferencesAndRejectsInlineCycles(): void
    {
        $batch = $this->writer->batch();
        $parent = $batch->create(self::TABLE, 1)->set('title', 'Parent');
        $child = $batch->create(self::TABLE, 1)->set('title', 'Child');
        $parent->relations('children')->add($child);
        self::assertTrue($batch->validate()->isValid());
        $child->relations('children')->add($parent);
        self::assertFalse($batch->validate()->isValid());
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
    }

    #[Test]
    public function reorderProtectsMmMembershipWhileSetMembersCanExplicitlyRemoveAMember(): void
    {
        $one = new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 1);
        $two = new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2);
        $draft = $this->writer->create(self::TABLE, 1)->set('title', 'Related');
        $draft->relations('related')->setMembers([$one, $two]);
        $record = $this->writer->save($draft);
        $edit = $this->writer->edit($record);
        try {
            $edit->relations('related')->reorder([$two]);
            self::fail('An ordering request must not detach an omitted member.');
        } catch (InvalidRecordValueException) {
            self::assertSame([1, 2], $this->relationIds($record->getUid()));
            self::assertFalse($edit->hasChanges());
        }
        $edit->relations('related')->setMembers([$two]);
        $this->writer->save($edit);
        self::assertSame([2], $this->relationIds($record->getUid()));
    }

    #[Test]
    public function dynamicForeignItemFiltersAreNotSilentlyIgnoredByRelationEncoding(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->setRelation('dynamic_reference', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 1));
        $this->expectException(UnsupportedRecordWriteException::class);
        $this->writer->save($draft);
    }

    /** @return list<int> */
    private function relationIds(int $uid): array
    {
        return array_map('intval', $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item_mm')
            ->select(['uid_foreign'], 'tx_recordwrite_item_mm', ['uid_local' => $uid], [], ['sorting' => 'ASC'])->fetchFirstColumn());
    }

    private function row(int $uid): array
    {
        $query = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $query->getRestrictions()->removeAll();
        return $query->select('*')->from(self::TABLE)->where($query->expr()->eq('uid', $query->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))->executeQuery()->fetchAssociative();
    }
}
