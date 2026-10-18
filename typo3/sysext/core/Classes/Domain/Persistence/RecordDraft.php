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

use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\RecordInterface;

/**
 * Mutable, unpersisted changes to a record. get() returns pending values, then raw original
 * database values; it never reads transformed relations or supplies TCA defaults.
 * @internal Experimental record write API, matching the stability of RecordFactory.
 */
final class RecordDraft
{
    private array $changes = [];
    private bool $consumed = false;
    private array $relationSets = [];

    /** @internal Created by RecordWriter. */
    public function __construct(
        public readonly string $table,
        public readonly int $pid,
        public readonly RecordWriteContext $context,
        public readonly ?RecordWriteTarget $target = null,
        private readonly ?RecordRelationResolver $relationResolver = null,
    ) {}

    public function set(string $field, mixed $value): self
    {
        $this->assertEditable();
        if (($this->relationSets[$field] ?? null) !== $value) {
            unset($this->relationSets[$field]);
        }
        $this->changes[$field] = $value;
        return $this;
    }

    public function setValues(array $values): self
    {
        foreach ($values as $field => $value) {
            $this->set((string)$field, $value);
        }
        return $this;
    }

    public function get(string $field): mixed
    {
        if (array_key_exists($field, $this->changes)) {
            return $this->changes[$field];
        }
        if ($this->target !== null && array_key_exists($field, $this->target->row)) {
            return $this->target->row[$field];
        }
        throw new InvalidRecordValueException('No original or pending value for field "' . $field . '".', 1792305005);
    }

    public function discard(string $field): self
    {
        $this->assertEditable();
        unset($this->changes[$field], $this->relationSets[$field]);
        return $this;
    }

    public function setRelation(string $field, RecordInterface|RecordReference|RecordDraft|null $record): self
    {
        return $this->set($field, $record === null ? [] : [$record]);
    }

    public function relations(string $field): RecordRelationSet
    {
        $this->assertEditable();
        if ($this->relationResolver === null) {
            throw new InvalidWriteContextException('This draft has no relation resolver.', 1792305052);
        }
        if (!isset($this->relationSets[$field])) {
            $values = array_key_exists($field, $this->changes) ? $this->changes[$field] : $this->relationResolver->load($this, $field);
            if (!is_array($values)) {
                throw new InvalidRecordValueException('The pending relation value is not a list.', 1792305053);
            }
            $resolve = fn($record): RecordReference|RecordDraft => $record instanceof RecordDraft && $record->target === null ? $record : $this->relationResolver->reference($record, $this->context);
            $this->relationSets[$field] = new RecordRelationSet(
                array_map($resolve, $values),
                $resolve,
                fn(RecordRelationSet $set): self => $this->set($field, $set)
            );
        }
        return $this->relationSets[$field];
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    /** @internal Only explicitly assigned values, including null. */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /** @internal An attempted write consumes the draft, including uncertain outcomes. */
    public function consume(): void
    {
        $this->assertEditable();
        $this->consumed = true;
    }

    /** @internal */
    public function assertEditable(): void
    {
        if ($this->consumed) {
            throw new InvalidWriteContextException('This draft was already saved or attempted. Reload the record and create a new draft.', 1792305006);
        }
    }
}
