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
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Domain\Persistence\Exception\DataHandlerExecutionException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteOutcome;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordWriteDataHandlerTest extends FunctionalTestCase
{
    private const TABLE = 'tx_recordwrite_item';
    protected array $coreExtensionsToLoad = ['workspaces'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];
    private RecordWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::flushInternalRuntimeCaches();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->writer = $this->get(RecordWriter::class);
    }

    public static function operations(): iterable
    {
        yield 'insert' => [true];
        yield 'update' => [false];
    }

    #[Test]
    #[DataProvider('operations')]
    public function failureInHistoryPreservesNativeExceptionAndMappingsWithoutInferringSuccess(bool $create): void
    {
        $draft = ($create ? $this->writer->create(self::TABLE, 1) : $this->writer->editByUid(self::TABLE, 1))->set('title', 'Persisted before history failure');
        $history = $this->createMock(RecordHistoryStore::class);
        $history->expects($this->once())->method($create ? 'addRecord' : 'modifyRecord')->willThrowException(new \RuntimeException('History storage unavailable', 1792340001));
        GeneralUtility::addInstance(RecordHistoryStore::class, $history);
        try {
            $this->writer->save($draft);
            self::fail('Expected the native history failure');
        } catch (DataHandlerExecutionException $exception) {
            self::assertSame(1792340001, $exception->getPrevious()?->getCode());
            $diagnostic = $exception->diagnostics->forDraft($draft);
            self::assertSame(RecordWriteOutcome::Unknown, $diagnostic->outcome);
            self::assertSame($create ? $exception->newRecordIds[$diagnostic->identifier] ?? null : 1, $diagnostic->uid);
            self::assertSame(1, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, ['title' => 'Persisted before history failure']));
        }
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    #[Test]
    #[DataProvider('operations')]
    public function silentSqlFailureIsNotTurnedIntoAConfirmationError(bool $create): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\WriteFailureHook::class;
        $batch = $this->writer->batch();
        $draft = ($create ? $batch->create(self::TABLE, 1) : $batch->editByUid(self::TABLE, 1))->set('title', 'Invalid SQL without logging');
        $result = $batch->execute();
        self::assertSame(RecordWriteOutcome::Unknown, $result->diagnostics->forDraft($draft)->outcome);
        self::assertSame([], $result->diagnostics->forDraft($draft)->errors);
        if ($create) {
            self::assertNull($result->recordFor($draft));
        } else {
            self::assertSame('Original', $result->recordFor($draft)->get('title'));
        }
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        self::assertSame('Original', $this->row(1)['title']);
    }

    #[Test]
    #[DataProvider('operations')]
    public function nativeSqlErrorsAreReported(bool $create): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\WriteFailureHook::class;
        $draft = ($create ? $this->writer->create(self::TABLE, 1) : $this->writer->editByUid(self::TABLE, 1))->set('title', 'Invalid SQL');
        try {
            $this->writer->save($draft);
            self::fail('Expected native SQL error');
        } catch (DataHandlerExecutionException $exception) {
            self::assertNotEmpty($exception->errors);
            self::assertSame(RecordWriteOutcome::Unknown, $exception->diagnostics->forDraft($draft)->outcome);
            self::assertSame('Original', $this->row(1)['title']);
        }
    }

    #[Test]
    #[DataProvider('operations')]
    public function successfulWritesStillWorkWhenLogStorageIsDisabled(bool $create): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\WriteFailureHook::class;
        $draft = ($create ? $this->writer->create(self::TABLE, 1) : $this->writer->editByUid(self::TABLE, 1))->set('title', 'Valid SQL without logging');
        self::assertSame('Valid SQL without logging', $this->writer->save($draft)->get('title'));
    }

    #[Test]
    public function nativeXclassCustomizationIsUsed(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]['className'] = Fixtures\CustomDataHandler::class;
        GeneralUtility::flushInternalRuntimeCaches();
        try {
            $record = $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set('title', 'Original input'));
            self::assertSame('Customized: Original input', $record->get('title'));
            self::assertSame('Customized: Original input', $this->row(1)['title']);
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]);
            GeneralUtility::flushInternalRuntimeCaches();
        }
    }

    #[Test]
    public function configuredNativeHandlerDefaultsAreUsed(): void
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->defaultValues = [self::TABLE => ['body' => 'Configured default body']];
        GeneralUtility::addInstance(DataHandler::class, $handler);
        $record = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Configured defaults'));
        self::assertSame('Configured default body', $this->row($record->getUid())['body']);
        $next = $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Fresh handler'));
        self::assertNull($this->row($next->getUid())['body']);
    }

    #[Test]
    public function containerReplacementIsUsedForRealPersistence(): void
    {
        $container = GeneralUtility::getContainer();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]['className'] = Fixtures\CustomDataHandler::class;
        GeneralUtility::flushInternalRuntimeCaches();
        $replacement = GeneralUtility::makeInstance(DataHandler::class);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]);
        GeneralUtility::flushInternalRuntimeCaches();
        GeneralUtility::setContainer(new class ($container, $replacement) implements ContainerInterface {
            public function __construct(private readonly ContainerInterface $container, private readonly DataHandler $handler) {}

            public function get(string $id): mixed
            {
                return $id === DataHandler::class ? $this->handler : $this->container->get($id);
            }

            public function has(string $id): bool
            {
                return $id === DataHandler::class || $this->container->has($id);
            }
        });
        try {
            $record = $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set('title', 'Container input'));
            self::assertSame('Customized: Container input', $record->get('title'));
            self::assertSame('Customized: Container input', $this->row(1)['title']);
        } finally {
            GeneralUtility::setContainer($container);
        }
    }

    #[Test]
    public function nativeTableAccessHooksRunDuringExecutionNotPreflight(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['checkModifyAccessList'][] = Fixtures\DenyTableWriteHook::class;
        $batch = $this->writer->batch();
        $draft = $batch->editByUid(self::TABLE, 1)->set('title', 'Denied by native hook');
        self::assertTrue($batch->validate()->isValid());
        try {
            $batch->execute();
            self::fail('Expected native table hook denial');
        } catch (DataHandlerExecutionException $exception) {
            self::assertNotEmpty($exception->errors);
            self::assertSame('Original', $this->row(1)['title']);
        }
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    #[Test]
    public function silentlySkippedFieldsReturnTheObservedStateWithoutConfirmingPersistence(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\WriteFailureHook::class;
        $batch = $this->writer->batch();
        $draft = $batch->editByUid(self::TABLE, 1)->set('title', 'Remove all fields');
        $result = $batch->execute();
        self::assertSame('Original', $result->recordFor($draft)->get('title'));
        self::assertSame(RecordWriteOutcome::Unknown, $result->diagnostics->forDraft($draft)->outcome);
        self::assertSame([], $result->diagnostics->forDraft($draft)->errors);
    }

    #[Test]
    #[DataProvider('relativeCommands')]
    public function incorrectRelativePositionIsDetectedEvenOnTheRequestedPage(string $command): void
    {
        $source = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $preceding = $this->writer->save($this->writer->editByUid(self::TABLE, 2));
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] = Fixtures\CommandPositionHook::class;
        $this->expectException(RecordPersistenceException::class);
        $this->expectExceptionMessage('requested relative position');
        $this->writer->$command($source, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::after($preceding));
    }

    public static function relativeCommands(): iterable
    {
        yield 'suppressed move' => ['move'];
        yield 'redirected copy' => ['copy'];
    }

    #[Test]
    public function silentCommandSkipsAndMissingMappingsAreAccepted(): void
    {
        $source = $this->writer->save($this->writer->editByUid(self::TABLE, 1));
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] = Fixtures\SuppressCommandsHook::class;
        self::assertNull($this->writer->copy($source, \TYPO3\CMS\Core\Domain\Persistence\RecordPosition::onPage(2)));
        self::assertNull($this->writer->localize($source, 1));
        self::assertNull($this->writer->copyToLanguage($source, 1));
        $this->writer->delete($source);
        self::assertSame(0, (int)$this->row(1)['deleted']);
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
    }

    #[Test]
    public function skippedRelationDoesNotTurnMissingConfirmationIntoAnException(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\WriteFailureHook::class;
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Skip relation');
        $draft->relations('related')->add(new \TYPO3\CMS\Core\Domain\Persistence\RecordReference(self::TABLE, 2));
        $saved = $this->writer->save($draft);
        self::assertSame('Skip relation', $saved->get('title'));
        self::assertSame([], $this->writer->edit($saved)->relations('related')->toArray());
    }

    #[Test]
    public function batchKeepsAvailableMappingsWhenAnotherMappingIsMissing(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\FailAfterWriteHook::class;
        $batch = $this->writer->batch();
        $available = $batch->create(self::TABLE, 1)->set('title', 'Available mapping');
        $missing = $batch->create(self::TABLE, 1)->set('title', 'Lose result mapping');
        $result = $batch->execute();
        $record = $result->recordFor($available);
        self::assertNotNull($record);
        self::assertSame('Available mapping', $record->get('title'));
        self::assertSame($record->getUid(), $result->diagnostics->forDraft($available)->uid);
        self::assertSame(RecordWriteOutcome::Unknown, $result->diagnostics->forDraft($available)->outcome);
        self::assertNull($result->recordFor($missing));
        self::assertNull($result->diagnostics->forDraft($missing)->uid);
        self::assertSame(RecordWriteOutcome::Unknown, $result->diagnostics->forDraft($missing)->outcome);
        self::assertSame(4, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
        $this->expectException(InvalidWriteContextException::class);
        $batch->execute();
    }

    private function row(int $uid): array
    {
        return $this->getConnectionPool()->getConnectionForTable(self::TABLE)->select(['*'], self::TABLE, ['uid' => $uid])->fetchAssociative();
    }
}
