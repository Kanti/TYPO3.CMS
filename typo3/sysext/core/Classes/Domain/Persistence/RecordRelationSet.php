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
    /** @var list<array{string, list<RecordReference|RecordDraft>}> */
    private array $operations = [];

    /** @param list<RecordReference|RecordDraft> $references */
    public function __construct(private array $references, private readonly \Closure $resolve, private readonly \Closure $changed, bool $replacement = false)
    {
        if ($replacement) {
            $this->operations[] = ['set', $references];
        }
    }

    public function add(RecordInterface|RecordReference|RecordDraft $record): self
    {
        return $this->change('add', [($this->resolve)($record)]);
    }

    public function remove(RecordInterface|RecordReference|RecordDraft $record): self
    {
        return $this->change('remove', [($this->resolve)($record)]);
    }

    /**
     * Intentionally specify complete membership, including removal of omitted records.
     * @param iterable<RecordInterface|RecordReference|RecordDraft> $records
     */
    public function setMembers(iterable $records): self
    {
        return $this->change('set', $this->resolveList($records));
    }

    public function clear(): self
    {
        return $this->setMembers([]);
    }

    /**
     * Order-only operation; changed membership is rejected again before execution.
     * @param iterable<RecordInterface|RecordReference|RecordDraft> $records
     */
    public function reorder(iterable $records): self
    {
        return $this->change('reorder', $this->resolveList($records));
    }

    private function resolveList(iterable $records): array
    {
        $references = [];
        foreach ($records as $record) {
            $reference = ($this->resolve)($record);
            $references[$this->key($reference)] = $reference;
        }
        return array_values($references);
    }

    private function change(string $operation, array $references): self
    {
        $preview = $this->apply($this->references, $operation, $references);
        ($this->changed)($this);
        if ($operation === 'set') {
            $this->operations = [];
        }
        $this->operations[] = [$operation, $references];
        $this->references = $preview;
        return $this;
    }

    /** @internal Rebase intent once against current, unfiltered backend membership. */
    public function resolveAgainst(array $current): array
    {
        foreach ($this->operations as [$operation, $references]) {
            $current = $this->apply($current, $operation, $references);
        }
        return $current;
    }

    private function apply(array $current, string $operation, array $references): array
    {
        $members = [];
        foreach ($current as $reference) {
            $members[$this->key($reference)] = $reference;
        }
        if ($operation === 'reorder') {
            $oldKeys = array_keys($members);
            $newKeys = array_map(fn($reference): string => $this->key($reference), $references);
            sort($oldKeys);
            sort($newKeys);
            if ($oldKeys !== $newKeys) {
                throw new InvalidRecordValueException('Reordering cannot change relation membership; reload the changed relation.', 1792305044);
            }
        }
        if ($operation === 'set' || $operation === 'reorder') {
            return $references;
        }
        foreach ($references as $reference) {
            if ($operation === 'remove') {
                unset($members[$this->key($reference)]);
            } else {
                $members[$this->key($reference)] = $reference;
            }
        }
        return array_values($members);
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
