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
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordAccessDeniedException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteOutcome;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Log\LogManager;
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
    public function failureInHistoryAfterSqlLeavesAnUnknownOutcomeAndPreventsRetry(bool $create): void
    {
        $draft = ($create ? $this->writer->create(self::TABLE, 1) : $this->writer->editByUid(self::TABLE, 1))->set('title', 'Persisted before history failure');
        $history = $this->createMock(RecordHistoryStore::class);
        $history->expects($this->once())->method($create ? 'addRecord' : 'modifyRecord')->willThrowException(new \RuntimeException('History storage unavailable', 1792340001));
        GeneralUtility::addInstance(RecordHistoryStore::class, $history);
        try {
            $this->writer->save($draft);
            self::fail('Expected a failure after the real SQL write');
        } catch (DataHandlerExecutionException $exception) {
            self::assertSame(1792340001, $exception->getPrevious()?->getCode());
            $diagnostic = $exception->diagnostics->forDraft($draft);
            self::assertSame(RecordWriteOutcome::Unknown, $diagnostic->outcome);
            self::assertNotNull($diagnostic->identity);
            self::assertSame('Persisted before history failure', $this->row($diagnostic->identity->physicalUid)['title']);
            self::assertSame([], $exception->writtenRecords);
        }
        $this->expectException(InvalidWriteContextException::class);
        $this->writer->save($draft);
    }

    #[Test]
    #[DataProvider('operations')]
    public function sqlErrorsCannotBeAcknowledgedEvenWhenLoggingIsDisabled(bool $create): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\WriteFailureHook::class;
        $draft = ($create ? $this->writer->create(self::TABLE, 1) : $this->writer->editByUid(self::TABLE, 1))->set('title', 'Invalid SQL without logging');
        try {
            $this->writer->save($draft);
            self::fail('Expected an unconfirmed write');
        } catch (RecordPersistenceException $exception) {
            self::assertSame(RecordWriteOutcome::Unknown, $exception->diagnostics->forDraft($draft)->outcome);
            self::assertSame([], $exception->errors);
            self::assertSame(2, $this->getConnectionPool()->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
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
    public function aDataHandlerReplacementCannotBeSilentlyBypassed(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Must not bypass the override');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]['className'] = Fixtures\CustomDataHandler::class;
        try {
            $this->writer->save($draft);
            self::fail('Expected explicit rejection of a DataHandler replacement');
        } catch (UnsupportedRecordWriteException) {
            self::assertSame('Original', $this->row(1)['title']);
            $draft->assertEditable();
        }
    }

    #[Test]
    public function aConfiguredNativeHandlerCannotBeSilentlyBypassed(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Must not bypass configured defaults');
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->defaultValues = [self::TABLE => ['hidden' => 1]];
        GeneralUtility::addInstance(DataHandler::class, $handler);
        try {
            $this->writer->save($draft);
            self::fail('Expected explicit rejection of a preconfigured DataHandler');
        } catch (UnsupportedRecordWriteException) {
            self::assertSame('Original', $this->row(1)['title']);
            $draft->assertEditable();
        }
    }

    #[Test]
    public function aContainerReplacementCannotBeSilentlyBypassed(): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Must not bypass container replacement');
        $container = GeneralUtility::getContainer();
        $replacement = $this->createMock(DataHandler::class);
        $replacement->expects($this->never())->method('start');
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
            $this->writer->save($draft);
            self::fail('Expected explicit rejection of a replacement container service');
        } catch (UnsupportedRecordWriteException) {
            self::assertSame('Original', $this->row(1)['title']);
            $draft->assertEditable();
        } finally {
            GeneralUtility::setContainer($container);
        }
    }

    #[Test]
    public function nativeTableAccessHooksStillRejectWritesBeforeConsumption(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['checkModifyAccessList'][] = Fixtures\DenyTableWriteHook::class;
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Denied by native hook');
        try {
            $this->writer->save($draft);
            self::fail('Expected native table hook denial');
        } catch (RecordAccessDeniedException) {
            self::assertSame('Original', $this->row(1)['title']);
            $draft->assertEditable();
        }
    }

    #[Test]
    public function anEmptyUpdateAfterHookProcessingCannotReuseAnEarlierAcknowledgement(): void
    {
        $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set('title', 'First confirmed'));
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\WriteFailureHook::class;
        $draft = $this->writer->editByUid(self::TABLE, 1)->set('title', 'Remove all fields');
        try {
            $this->writer->save($draft);
            self::fail('Expected an unconfirmed write');
        } catch (RecordPersistenceException $exception) {
            self::assertSame(RecordWriteOutcome::Unknown, $exception->diagnostics->forDraft($draft)->outcome);
            self::assertSame('First confirmed', $this->row(1)['title']);
        }
    }

    #[Test]
    public function theDedicatedHandlerPreservesTheNativeLogChannelAndContainerService(): void
    {
        self::assertSame(DataHandler::class, GeneralUtility::makeInstance(DataHandler::class)::class);
        $this->writer->save($this->writer->create(self::TABLE, 1)->set('title', 'Native logging configuration'));
        self::assertSame(DataHandler::class, GeneralUtility::makeInstance(DataHandler::class)::class);
        $loggerNames = $this->get(LogManager::class)->getLoggerNames();
        self::assertContains('TYPO3.CMS.Core.DataHandling.DataHandler', $loggerNames);
        self::assertNotContains('TYPO3.CMS.Core.Domain.Persistence.RecordWriteDataHandler', $loggerNames);
    }

    private function row(int $uid): array
    {
        return $this->getConnectionPool()->getConnectionForTable(self::TABLE)->select(['*'], self::TABLE, ['uid' => $uid])->fetchAssociative();
    }
}
