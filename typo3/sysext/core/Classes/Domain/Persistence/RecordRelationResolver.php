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

use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal Owns backend relation reads and DataHandler reference encoding. */
final readonly class RecordRelationResolver
{
    public function __construct(private RecordWriteTargetResolver $targets, private DataHandlerRecordWriter $adapter) {}

    public function reference(RecordInterface|RecordReference|RecordDraft $record, RecordWriteContext $context): RecordReference
    {
        if ($record instanceof RecordDraft) {
            if ($record->target === null) {
                throw new InvalidRecordValueException('A new draft has no persisted reference.', 1792305068);
            }
            $record = new RecordReference($record->table, $record->target->uid);
        }
        $target = $record instanceof RecordInterface ? $this->targets->fromRecord($record, $context)
            : $this->targets->byUid($record->table, $record->uid, $context);
        return new RecordReference($target->table, $target->uid);
    }

    /** @return list<RecordReference> */
    public function load(RecordDraft $draft, string $name): array
    {
        $field = $this->targets->schema($draft->table, $draft->context)->getField($name);
        $field = $this->supportedField($field);
        if ($draft->target === null) {
            return [];
        }
        $handler = GeneralUtility::makeInstance(RelationHandler::class);
        $handler->setWorkspaceId($draft->context->workspaceId);
        $handler->initializeForField($draft->table, $field, $draft->target->row, $draft->target->row[$name] ?? null);
        $handler->processDeletePlaceholder();
        return array_values(array_map(static fn(array $item): RecordReference => new RecordReference($item['table'], (int)$item['id']), $handler->itemArray));
    }

    public function encode(FieldTypeInterface $field, mixed $value, RecordDraft $draft, array $identifiers = []): string
    {
        $field = $this->supportedField($field);
        $records = $value instanceof RecordRelationSet ? $value->toArray() : $value;
        if (!is_array($records) || !array_is_list($records)) {
            throw new InvalidRecordValueException('Relations require an explicit list of records or RecordReference objects.', 1792305045);
        }
        $config = $field->getConfiguration();
        $allowed = array_map(static fn($relation): string => $relation->toTable(), $field->getRelations());
        $encoded = [];
        foreach ($records as $record) {
            if ($record instanceof RecordDraft && $record->target === null) {
                $identifier = $identifiers[spl_object_id($record)] ?? null;
                if (!is_string($identifier) || !in_array($record->table, $allowed, true)) {
                    throw new InvalidRecordValueException('New related records must belong to the same batch and an allowed table.', 1792305060);
                }
                $encoded['new:' . spl_object_id($record)] = $field->getType() === 'group' ? $record->table . '_' . $identifier : $identifier;
                continue;
            }
            if (!$record instanceof RecordInterface && !$record instanceof RecordReference && !$record instanceof RecordDraft) {
                throw new InvalidRecordValueException('Untyped relation identifiers are not accepted.', 1792305046);
            }
            $reference = $this->reference($record, $draft->context);
            if (!in_array($reference->table, $allowed, true)) {
                throw new InvalidRecordValueException('Reference table is not allowed for "' . $field->getName() . '".', 1792305047);
            }
            $encodedUid = $reference->uid;
            if ($field->getType() === 'inline' && !empty($config['foreign_field'])) {
                $child = $this->targets->byUid($reference->table, $reference->uid, $draft->context);
                $this->adapter->assertCanChangeRecord($child, $draft->context);
                if ($reference->table === $draft->table && $reference->uid === $draft->target?->uid) {
                    throw new InvalidRecordValueException('A record cannot be its own inline child.', 1792305055);
                }
                if ($reference->table === $draft->table && $draft->target !== null) {
                    $ancestor = $draft->target;
                    $seen = [];
                    while ($ancestor !== null) {
                        if ($ancestor->uid === $reference->uid || isset($seen[$ancestor->uid])) {
                            throw new InvalidRecordValueException('An inline relation must not create an ancestor cycle.', 1792305069);
                        }
                        $seen[$ancestor->uid] = true;
                        $parent = (int)($ancestor->row[$config['foreign_field']] ?? 0);
                        $ancestor = $parent > 0 ? $this->targets->byUid($draft->table, $parent, $draft->context) : null;
                    }
                }
                $owner = (int)($child->row[$config['foreign_field']] ?? 0);
                if ($draft->context->workspaceId > 0) {
                    $childWorkspace = (int)($child->row['t3ver_wsid'] ?? 0);
                    $parentWorkspace = (int)($draft->target?->row['t3ver_wsid'] ?? 0);
                    if ($childWorkspace === 0 && ($owner === 0 || $parentWorkspace > 0)) {
                        throw new UnsupportedRecordWriteException('An unversioned inline child cannot be attached to this workspace parent. Create the child in the batch or edit its workspace version first.', 1792305054);
                    }
                    $encodedUid = (int)$child->row['uid'];
                }
                if ($owner !== 0 && !in_array($owner, [$draft->target?->uid, $draft->target?->row['uid']], true)) {
                    throw new InvalidRecordValueException('The inline child already belongs to another parent.', 1792305048);
                }
                $parentTarget = $draft->target;
                $parentLanguage = $parentTarget !== null ? $parentTarget->languageId : 0;
                if ($child->languageId !== $parentLanguage) {
                    throw new InvalidRecordValueException('Inline child and parent must have the same language.', 1792305049);
                }
            }
            $encoded[$reference->key()] = $field->getType() === 'group' ? $reference->table . '_' . $reference->uid : (string)$encodedUid;
        }
        $maximum = (int)($config['maxitems'] ?? 0);
        if (count($encoded) < (int)($config['minitems'] ?? 0) || ($maximum > 0 && count($encoded) > $maximum)) {
            throw new InvalidRecordValueException('Relation cardinality is outside the configured limits.', 1792305050);
        }
        return implode(',', $encoded);
    }

    /** @param list<RecordDraft> $drafts */
    public function assertNoInlineCycles(array $drafts): void
    {
        $edges = [];
        $owners = [];
        foreach ($drafts as $draft) {
            $key = $draft->target !== null ? $draft->table . ':' . $draft->target->uid : 'new:' . spl_object_id($draft);
            foreach ($draft->getChanges() as $name => $value) {
                $field = $this->targets->schema($draft->table, $draft->context)->getField($name);
                if ($field->getType() !== 'inline') {
                    continue;
                }
                foreach ($value instanceof RecordRelationSet ? $value->toArray() : $value as $record) {
                    $childKey = $record instanceof RecordDraft && $record->target === null
                        ? 'new:' . spl_object_id($record) : $this->reference($record, $draft->context)->key();
                    if (isset($owners[$childKey]) && $owners[$childKey] !== $key) {
                        throw new InvalidRecordValueException('An inline child cannot have two batch parents.', 1792305071);
                    }
                    $owners[$childKey] = $key;
                    $edges[$key][] = $childKey;
                }
            }
        }
        $visited = [];
        $visiting = [];
        $visit = function (string $node) use (&$visit, &$visited, &$visiting, $edges): void {
            if (isset($visiting[$node])) {
                throw new InvalidRecordValueException('The batch contains an inline relation cycle.', 1792305070);
            }
            if (isset($visited[$node])) {
                return;
            }
            $visiting[$node] = true;
            foreach ($edges[$node] ?? [] as $child) {
                $visit($child);
            }
            unset($visiting[$node]);
            $visited[$node] = true;
        };
        foreach (array_keys($edges) as $node) {
            $visit($node);
        }
    }

    /**
     * DataHandler does not detach missing foreign_field children merely by replacing the parent list.
     * Detachment is an explicit child datamap update, retaining DataHandler access checks and history.
     */
    public function detachments(RecordDraft $draft): array
    {
        $data = [];
        foreach ($draft->getChanges() as $name => $value) {
            $field = $this->targets->schema($draft->table, $draft->context)->getField($name);
            $config = $field->getConfiguration();
            if ($draft->target === null || $field->getType() !== 'inline' || empty($config['foreign_field'])) {
                continue;
            }
            $records = $value instanceof RecordRelationSet ? $value->toArray() : $value;
            $retained = [];
            foreach ($records as $record) {
                $retained[] = $record instanceof RecordDraft && $record->target === null ? 'new:' . spl_object_id($record) : $this->reference($record, $draft->context)->key();
            }
            foreach ($this->load($draft, $name) as $reference) {
                $reference = $this->reference($reference, $draft->context);
                if (!in_array($reference->key(), $retained, true)) {
                    $this->adapter->assertCanChangeRecord($this->targets->byUid($reference->table, $reference->uid, $draft->context), $draft->context);
                    $data[$reference->table][$reference->uid][$config['foreign_field']] = 0;
                }
            }
        }
        return $data;
    }

    public function unchanged(RecordDraft $draft, string $name, RecordWriteTarget $current): bool
    {
        $value = $draft->getChanges()[$name];
        $records = $value instanceof RecordRelationSet ? $value->toArray() : $value;
        $expected = [];
        foreach ($records as $record) {
            if ($record instanceof RecordDraft && $record->target === null) {
                return false;
            }
            $expected[] = $this->reference($record, $draft->context)->key();
        }
        $fresh = new RecordDraft($current->table, (int)$current->row['pid'], $draft->context, $current);
        $actual = array_map(fn(RecordReference $reference): string => $this->reference($reference, $draft->context)->key(), $this->load($fresh, $name));
        return array_values(array_unique($expected)) === $actual;
    }

    public function verify(RecordDraft $draft, RecordWriteTarget $saved, array $newUids = []): void
    {
        $fresh = new RecordDraft($saved->table, (int)$saved->row['pid'], $draft->context, $saved);
        foreach ($draft->getChanges() as $name => $value) {
            $field = $this->targets->schema($draft->table, $draft->context)->getField($name);
            if (!$field instanceof RelationalFieldTypeInterface) {
                continue;
            }
            $records = $value instanceof RecordRelationSet ? $value->toArray() : $value;
            $expected = [];
            foreach ($records as $record) {
                $expected[] = $record instanceof RecordDraft && $record->target === null
                    ? $this->reference(new RecordReference($record->table, $newUids[spl_object_id($record)]), $draft->context)->key()
                    : $this->reference($record, $draft->context)->key();
            }
            $actual = [];
            foreach ($this->load($fresh, $name) as $reference) {
                $actual[] = $this->reference($reference, $draft->context)->key();
            }
            if (array_values(array_unique($expected)) !== $actual) {
                throw new RecordPersistenceException('The saved relation members or their order differ from the requested changes.', 1792305103);
            }
        }
    }

    private function supportedField(FieldTypeInterface $field): FieldTypeInterface&RelationalFieldTypeInterface
    {
        $conf = $field->getConfiguration();
        if (!$field instanceof RelationalFieldTypeInterface || $field->getType() === 'file'
            || (!empty($conf['MM_opposite_field']) && $field->getType() !== 'category') || ($field->getType() === 'inline' && empty($conf['foreign_field'])) || !empty($conf['symmetric_field']) || !empty($conf['foreign_selector']) || !empty($conf['foreign_table_field']) || !empty($conf['foreign_match_fields']) || !empty($conf['multiple'])) {
            throw new UnsupportedRecordWriteException('This relation requires unsupported file, inverse-MM, discriminator or duplicate-member semantics.', 1792305051);
        }
        return $field;
    }
}
