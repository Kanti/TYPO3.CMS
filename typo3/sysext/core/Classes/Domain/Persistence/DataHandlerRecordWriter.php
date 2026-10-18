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

namespace TYPO3\CMS\Core\Domain\Persistence;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Persistence\Exception\DataHandlerExecutionException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal Isolates mutable DataHandler instances and its result mappings. */
final readonly class DataHandlerRecordWriter
{
    public function execute(array $data, array $commands, RecordWriteContext $context): DataHandler
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        try {
            $handler->start($data, $commands, $context->user);
            if ($data !== []) {
                $handler->process_datamap();
            }
            if ($commands !== []) {
                $handler->process_cmdmap();
            }
        } catch (\Throwable $exception) {
            throw new DataHandlerExecutionException('DataHandler execution failed; persistence may have partially succeeded.', 1792305101, array_values($handler->errorLog), $exception, $handler->substNEWwithIDs);
        } finally {
            // Existing backend caches have no shared invalidation tag.
            GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        }
        if ($handler->errorLog !== []) {
            throw new DataHandlerExecutionException('DataHandler reported errors; persistence may have partially succeeded.', 1792305102, array_values($handler->errorLog), null, $handler->substNEWwithIDs);
        }
        return $handler;
    }
}
