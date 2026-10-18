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
use TYPO3\CMS\Core\Authentication\Event\AfterGroupsResolvedEvent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteContext;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Tests\Functional\Domain\Persistence\Fixtures\RecordWriteQueryLogger;
use TYPO3\CMS\Core\Tests\Functional\Domain\Persistence\Fixtures\RecordWriteQueryMiddleware;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordWritePerformanceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];
    protected array $configurationToUseInTestInstance = ['DB' => ['globalDriverMiddlewares' => ['record-write-measurement' => ['target' => RecordWriteQueryMiddleware::class]]]];

    public static function sizes(): iterable
    {
        foreach ([1, 100, 500, 1000] as $count) {
            yield (string)$count => [$count];
        }
    }

    #[Test]
    #[DataProvider('sizes')]
    public function batchesResolveTheirExecutionIdentityAConstantNumberOfTimes(int $count): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/system-user.csv');
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $container = $this->get('service_container');
        $state = new class {
            public int $initializations = 0;
        };
        $container->set('record-write-initializations', static function (AfterGroupsResolvedEvent $event) use ($state): void {
            if (($event->getUserData()['username'] ?? '') === '_cli_') {
                $state->initializations++;
            }
        });
        $container->get(ListenerProvider::class)->addListener(AfterGroupsResolvedEvent::class, 'record-write-initializations');
        $start = hrtime(true);
        RecordWriteContext::capture($this->get(Context::class));
        $initializationMs = (hrtime(true) - $start) / 1e6;
        $state->initializations = 0;
        RecordWriteQueryLogger::$queries = RecordWriteQueryLogger::$userQueries = 0;
        $writer = $this->get(RecordWriter::class);
        $start = hrtime(true);
        $batch = $writer->batch();
        for ($index = 0; $index < $count; $index++) {
            $batch->create('tx_recordwrite_item', 1)->set('title', 'Import ' . $index);
        }
        $constructionMs = (hrtime(true) - $start) / 1e6;
        $batch->execute();
        $metrics = ['count' => $count, 'initializations' => $state->initializations, 'user_queries' => RecordWriteQueryLogger::$userQueries, 'queries' => RecordWriteQueryLogger::$queries, 'initialization_ms' => $initializationMs, 'construction_ms' => $constructionMs, 'total_ms' => (hrtime(true) - $start) / 1e6];
        $file = getenv('RECORD_WRITE_BENCHMARK_FILE') ?: Environment::getVarPath() . '/record-write-performance.jsonl';
        file_put_contents($file, json_encode($metrics, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
        self::assertSame($count + 2, $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->count('*', 'tx_recordwrite_item', []));
        self::assertLessThanOrEqual(3, $state->initializations, json_encode($metrics, JSON_THROW_ON_ERROR));
        self::assertLessThanOrEqual(8, RecordWriteQueryLogger::$userQueries);
    }
}
