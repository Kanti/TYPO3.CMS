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

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * @internal Dedicated, non-shared DataHandler for the Record Write API.
 * Reuses protected Core defaults/ACLs and observes native success notifications.
 * All persistence, authorization and logging remain in the parent implementation.
 */
#[Autoconfigure(public: true, shared: false), Channel(DataHandler::class)]
final class RecordWriteDataHandler extends DataHandler
{
    /** @var array<string, array<int, true>> */
    private array $writtenRecords = [];

    public function getDefaultsForNewRecord(string $table, int $pageId, array $incomingFieldArray = []): array
    {
        $recordContext = ['pid' => $pageId] + $incomingFieldArray;
        return $this->applyDefaultsForFieldArray($table, $pageId, $this->newFieldArray($table, $recordContext), $recordContext);
    }

    public function canModifyTable(string $table): bool
    {
        return $this->checkModifyAccessList($table);
    }

    /** @return array<string, array<int, true>> */
    public function getWrittenRecords(): array
    {
        return $this->writtenRecords;
    }

    public function log($table, $recuid, $action, $_, $error, $details, $__ = null, array $data = [], $event_pid = -1)
    {
        $logId = parent::log($table, $recuid, $action, $_, $error, $details, $__, $data, $event_pid);
        // Datamap inserts/updates emit success notifications after SQL and history processing.
        // Observe the notification even when storage of sys_log entries is disabled.
        // An exception before this point leaves the outcome unknown, even if SQL succeeded.
        if ($error === SystemLogErrorClassification::MESSAGE
            && in_array($action, [SystemLogDatabaseAction::INSERT, SystemLogDatabaseAction::UPDATE], true)
            && (int)$recuid > 0) {
            $this->writtenRecords[(string)$table][(int)$recuid] = true;
        }
        return $logId;
    }
}
