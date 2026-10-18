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

use TYPO3\CMS\Core\DataHandling\ItemProcessingService;
use TYPO3\CMS\Core\DataHandling\ItemsProcessorContext;
use TYPO3\CMS\Core\DataHandling\ItemsProcessorExecutionFailedException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Schema\Struct\SelectItemCollection;

/** @internal Runs the same item processors used by FormEngine with the prospective record. */
final readonly class RecordSelectItemResolver
{
    public function __construct(private ItemProcessingService $processing, private DataHandlerRecordWriter $adapter) {}

    public function items(FieldTypeInterface $field, RecordDraft $draft, ?RecordWriteTarget $target): array
    {
        $configuration = $field->getConfiguration();
        $pid = $target !== null ? (int)$target->row['pid'] : $draft->pid;
        $row = array_replace($target->row ?? $this->adapter->defaults($draft), $draft->getChanges(), ['pid' => $pid, 'uid' => $target->row['uid'] ?? 'NEWrecordwrite']);
        $context = new ItemsProcessorContext(
            table: $draft->table,
            field: $field->getName(),
            row: $row,
            fieldConfiguration: $configuration,
            processorParameters: [],
            realPid: $pid,
            site: $this->processing->resolveSite($pid),
        );
        try {
            return array_map(static fn(SelectItem $item): array => $item->toArray(), $this->processing->processItems(SelectItemCollection::createFromArray($configuration['items'] ?? [], $field->getType()), $context)->toArray());
        } catch (ItemsProcessorExecutionFailedException $exception) {
            throw new InvalidRecordValueException('Cannot determine selectable values for "' . $field->getName() . '".', 1792310010, $exception);
        }
    }
}
