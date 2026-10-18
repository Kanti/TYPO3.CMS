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
use TYPO3\CMS\Core\Domain\RecordInterface;

/**
 * Ordered relation changes. Loaded from backend relation metadata, never a frontend collection.
 * @internal Experimental record write API.
 */
final class RecordRelationSet
{
    /** @param list<RecordReference|RecordDraft> $references */
    public function __construct(private array $references, private readonly \Closure $resolve, private readonly \Closure $changed) {}

    public function add(RecordInterface|RecordReference|RecordDraft $record): self
    {
        $reference = ($this->resolve)($record);
        foreach ($this->references as $existing) {
            if ($this->key($existing) === $this->key($reference)) {
                return $this;
            }
        }
        return $this->setMembers([...$this->references, $reference]);
    }

    public function remove(RecordInterface|RecordReference|RecordDraft $record): self
    {
        $key = $this->key(($this->resolve)($record));
        return $this->setMembers(array_values(array_filter($this->references, fn(RecordReference|RecordDraft $reference): bool => $this->key($reference) !== $key)));
    }

    /**
     * Set the complete ordered membership, explicitly detaching omitted records.
     * @param iterable<RecordInterface|RecordReference|RecordDraft> $records
     */
    public function setMembers(iterable $records): self
    {
        $references = [];
        foreach ($records as $record) {
            $reference = ($this->resolve)($record);
            $references[$this->key($reference)] = $reference;
        }
        // Validate the owning draft before mutating the set, including retained handles after save.
        ($this->changed)($this);
        $this->references = array_values($references);
        return $this;
    }

    public function clear(): self
    {
        return $this->setMembers([]);
    }

    /**
     * Change order only; an incomplete or different member set is rejected.
     * @param iterable<RecordInterface|RecordReference|RecordDraft> $records
     */
    public function reorder(iterable $records): self
    {
        $references = [];
        foreach ($records as $record) {
            $reference = ($this->resolve)($record);
            $references[$this->key($reference)] = $reference;
        }
        $oldKeys = array_map(fn(RecordReference|RecordDraft $reference): string => $this->key($reference), $this->references);
        $newKeys = array_keys($references);
        sort($oldKeys);
        sort($newKeys);
        if ($oldKeys !== $newKeys) {
            throw new InvalidRecordValueException('Reordering must contain exactly the existing relation members.', 1792305044);
        }
        return $this->setMembers($references);
    }

    private function key(RecordReference|RecordDraft $reference): string
    {
        return $reference instanceof RecordDraft ? 'new:' . spl_object_id($reference) : $reference->key();
    }

    /** @return list<RecordReference|RecordDraft> */
    public function toArray(): array
    {
        return $this->references;
    }
}
