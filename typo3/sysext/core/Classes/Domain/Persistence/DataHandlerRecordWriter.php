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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Persistence\Exception\DataHandlerExecutionException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordAccessDeniedException;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\VirtualRecord;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal Isolates mutable DataHandler instances and its result mappings. */
final readonly class DataHandlerRecordWriter
{
    public function defaults(RecordDraft $draft): array
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], [], $draft->context->user);
        return $handler->getDefaultsForNewRecord($draft->table, $draft->pid, $draft->getChanges());
    }

    public function assertCanChangeRecord(RecordWriteTarget $target, RecordWriteContext $context): void
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], [], $context->user);
        $page = $target->table === 'pages' ? $target->row : ((int)$target->row['pid'] > 0
            ? BackendUtility::getRecordWSOL('pages', (int)$target->row['pid'])
            : VirtualRecord::RootPage);
        if ($page === null || !$handler->canModifyTable($target->table)
            || !$context->user->checkRecordEditAccess($target->table, $target->row)->isAllowed
            || !$handler->hasPageContextPermission($target->table, Permission::PAGE_SHOW, $page)
            || !$handler->hasPermissionToUpdate($target->table, $page)) {
            throw new RecordAccessDeniedException('The record cannot be changed by this backend user.', 1792305072);
        }
    }

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
            throw new DataHandlerExecutionException('DataHandler execution failed; persistence may have partially succeeded.', 1792305101, array_values($handler->errorLog), $exception, $handler->getWrittenRecords(), $handler->substNEWwithIDs);
        } finally {
            // Existing backend caches have no shared invalidation tag.
            GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        }
        if ($handler->errorLog !== []) {
            throw new DataHandlerExecutionException('DataHandler reported errors; persistence may have partially succeeded.', 1792305102, array_values($handler->errorLog), null, $handler->getWrittenRecords(), $handler->substNEWwithIDs);
        }
        return $handler;
    }
}
