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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordNotFoundException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;

/**
 * Backend record writes through DataHandler, without mutating read-side records.
 * @internal Experimental, matching the stability of the Core Record API.
 */
#[Autoconfigure(public: true)]
final readonly class RecordWriter
{
    public function __construct(
        private Context $context,
        private RecordWriteTargetResolver $targets,
        private RecordFieldNormalizer $normalizer,
        private DataHandlerRecordWriter $adapter,
        private RecordFactory $recordFactory,
        private RecordRelationResolver $relations,
    ) {}

    public function create(string $table, int $pid): RecordDraft
    {
        $context = RecordWriteContext::capture($this->context);
        $this->targets->schema($table, $context);
        if ($pid < 0) {
            throw new InvalidRecordValueException('Creation requires a non-negative page ID.', 1792305035);
        }
        return new RecordDraft($table, $this->storagePageId($pid, $context), $context, null, $this->relations);
    }

    public function edit(RecordInterface $record): RecordDraft
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->fromRecord($record, $context);
        return new RecordDraft($target->table, (int)$target->row['pid'], $context, $target, $this->relations);
    }

    public function editByUid(string $table, int $uid): RecordDraft
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->byUid($table, $uid, $context);
        return new RecordDraft($table, (int)$target->row['pid'], $context, $target, $this->relations);
    }

    public function save(RecordDraft $draft): RecordInterface
    {
        return $this->saveAll([$draft])->recordFor($draft);
    }

    public function batch(): RecordWriteBatch
    {
        return new RecordWriteBatch($this);
    }

    /** @internal @param list<RecordDraft> $drafts */
    public function saveAll(array $drafts): RecordWriteResult
    {
        $identifiers = [];
        $targets = [];
        $values = [];
        $data = [];
        foreach ($drafts as $draft) {
            $draft->assertEditable();
            $draft->context->assertCurrent($this->context);
            $key = spl_object_id($draft);
            if (isset($identifiers[$key])) {
                throw new InvalidRecordValueException('A draft must occur only once in a batch.', 1792305057);
            }
            $identifiers[$key] = $draft->target !== null ? $draft->target->uid : 'NEW' . bin2hex(random_bytes(16));
            if ($draft->target !== null) {
                $targetKey = $draft->table . ':' . $draft->target->uid;
                if (isset($targets[$targetKey])) {
                    throw new InvalidRecordValueException('Conflicting drafts target the same logical record.', 1792305058);
                }
                $targets[$targetKey] = true;
            }
        }
        foreach ($drafts as $draft) {
            $key = spl_object_id($draft);
            $schema = $this->targets->schema($draft->table, $draft->context);
            $currentTarget = $draft->target !== null ? $this->targets->byUid($draft->table, $draft->target->uid, $draft->context) : null;
            $values[$key] = $this->normalizer->changes($draft, $schema, $identifiers, $currentTarget);
            if ($currentTarget !== null) {
                if ($currentTarget->languageId !== $draft->target->languageId) {
                    throw new InvalidRecordValueException('The record language changed since draft creation.', 1792305036);
                }
                // Best-effort no-op detection, not atomic optimistic locking.
                foreach ($values[$key] as $name => $value) {
                    if ($schema->getField($name) instanceof RelationalFieldTypeInterface) {
                        if ($this->relations->unchanged($draft, $name, $currentTarget)) {
                            unset($values[$key][$name]);
                        }
                        continue;
                    }
                    $original = $currentTarget->row[$name] ?? null;
                    if ($value === $original || (is_scalar($value) && $original !== null && (string)$value === (string)$original)) {
                        unset($values[$key][$name]);
                    }
                }
                if ($values[$key] === []) {
                    $this->adapter->assertCanChangeRecord($currentTarget, $draft->context);
                }
            } else {
                if ($draft->pid > 0) {
                    $this->targets->byUid('pages', $draft->pid, $draft->context);
                }
                $systemValues = ['pid' => $draft->pid];
                if ($schema->isLanguageAware()) {
                    $systemValues[$schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()] = 0;
                }
                $values[$key] = $systemValues + $values[$key];
            }
            if ($values[$key] !== []) {
                $data[$draft->table][$identifiers[$key]] = $values[$key];
            }
        }
        $this->relations->assertNoInlineCycles($drafts);
        foreach ($drafts as $draft) {
            foreach ($this->relations->detachments($draft) as $table => $rows) {
                foreach ($rows as $uid => $fields) {
                    if (isset($data[$table][$uid]) || isset($targets[$table . ':' . $uid])) {
                        throw new InvalidRecordValueException('Inline detachment conflicts with another draft.', 1792305059);
                    }
                    $data[$table][$uid] = $fields;
                }
            }
        }
        foreach ($drafts as $draft) {
            $draft->consume();
        }
        $handler = $data !== [] ? $this->adapter->execute($data, [], $drafts[0]->context) : null;
        $saved = [];
        $newUids = [];
        try {
            foreach ($drafts as $draft) {
                $key = spl_object_id($draft);
                $uid = $draft->target !== null ? $draft->target->uid : ($handler?->substNEWwithIDs[$identifiers[$key]] ?? 0);
                if (!$uid) {
                    throw new RecordPersistenceException('DataHandler did not resolve a new record identifier.', 1792305104);
                }
                $saved[$key] = $this->targets->byUid($draft->table, $uid, $draft->context);
                $newUids[$key] = $saved[$key]->uid;
                $expectedLanguage = $draft->target !== null ? $draft->target->languageId : 0;
                if ($saved[$key]->languageId !== $expectedLanguage || ($draft->context->workspaceId > 0 && $values[$key] !== [] && (int)($saved[$key]->row['t3ver_wsid'] ?? 0) !== $draft->context->workspaceId)) {
                    throw new RecordPersistenceException('The saved record does not belong to the requested language and workspace.', 1792305105);
                }
                if ($values[$key] !== [] && !$handler?->hasWrittenRecord($draft->table, (int)$saved[$key]->row['uid'])) {
                    throw new RecordPersistenceException('DataHandler did not confirm a write to the requested record.', 1792305106);
                }
            }
            $records = [];
            foreach ($drafts as $draft) {
                $key = spl_object_id($draft);
                $this->relations->verify($draft, $saved[$key], $newUids);
                $records[$key] = [$draft, $this->record($saved[$key])];
            }
            return new RecordWriteResult($records);
        } catch (RecordPersistenceException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RecordPersistenceException('The write completed but its results could not be reloaded.', 1792305107, [], $exception);
        }
    }

    public function delete(RecordInterface $record): void
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->fromRecord($record, $context);
        $this->adapter->execute([], [$target->table => [$target->uid => ['delete' => 1]]], $context);
        try {
            $this->targets->byUid($target->table, $target->uid, $context);
        } catch (RecordNotFoundException) {
            return;
        }
        throw new RecordPersistenceException('DataHandler did not confirm deletion of the record.', 1792305108);
    }

    public function restore(string $table, int $uid): RecordInterface
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->byUid($table, $uid, $context, true);
        if ($context->workspaceId !== 0) {
            throw new UnsupportedRecordWriteException('Restore is currently supported only in Live.', 1792305038);
        }
        $schema = $this->targets->schema($table, $context);
        if (!$schema->hasCapability(TcaSchemaCapability::SoftDelete)
            || empty($target->row[$schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName()])) {
            throw new InvalidRecordValueException('Restore requires a soft-deleted record.', 1792305039);
        }
        $this->adapter->execute([], [$table => [$target->uid => ['undelete' => 1]]], $context);
        return $this->reloadAfterCommand($table, $target->uid, $context);
    }

    public function move(RecordInterface $record, RecordPosition $position): RecordInterface
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->fromRecord($record, $context);
        [$destination, $pid] = $this->destination($position, $target, $context);
        $this->adapter->execute([], [$target->table => [$target->uid => ['move' => $destination]]], $context);
        $saved = $this->reloadAfterCommand($target->table, $target->uid, $context);
        if ($saved->getPid() !== $pid) {
            throw new RecordPersistenceException('DataHandler did not move the record to the requested page.', 1792305109);
        }
        return $saved;
    }

    public function copy(RecordInterface $record, RecordPosition $position): RecordInterface
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->fromRecord($record, $context);
        [$destination, $pid] = $this->destination($position, $target, $context);
        $handler = $this->adapter->execute([], [$target->table => [$target->uid => ['copy' => $destination]]], $context);
        $uid = (int)($handler->copyMappingArray_merged[$target->table][$target->uid] ?? 0);
        if ($uid <= 0 || $uid === $target->uid) {
            throw new RecordPersistenceException('DataHandler did not resolve the copied record.', 1792305110);
        }
        $saved = $this->reloadAfterCommand($target->table, $uid, $context);
        if ($saved->getPid() !== $pid) {
            throw new RecordPersistenceException('The copy was not created on the requested page.', 1792305111);
        }
        return $saved;
    }

    public function localize(RecordInterface $record, int $languageId): RecordInterface
    {
        return $this->languageCommand($record, $languageId, 'localize');
    }

    public function copyToLanguage(RecordInterface $record, int $languageId): RecordInterface
    {
        return $this->languageCommand($record, $languageId, 'copyToLanguage');
    }

    public function discardWorkspaceVersion(RecordInterface $record): void
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->fromRecord($record, $context);
        if ($context->workspaceId <= 0 || (int)($target->row['t3ver_wsid'] ?? 0) !== $context->workspaceId) {
            throw new InvalidRecordValueException('Discard requires a version in the current workspace.', 1792305040);
        }
        $this->adapter->execute([], [$target->table => [$target->uid => ['discard' => 1]]], $context);
        if ($this->targets->row($target->table, (int)$target->row['uid']) !== null) {
            throw new RecordPersistenceException('DataHandler did not discard the workspace version.', 1792305112);
        }
    }

    private function languageCommand(RecordInterface $record, int $languageId, string $command): RecordInterface
    {
        $context = RecordWriteContext::capture($this->context);
        $target = $this->targets->fromRecord($record, $context);
        if ($languageId <= 0 || $target->languageId !== 0 || !$this->targets->schema($target->table, $context)->isLanguageAware()) {
            throw new InvalidRecordValueException('Localization requires a default-language source and a positive target language.', 1792305041);
        }
        $handler = $this->adapter->execute([], [$target->table => [$target->uid => [$command => $languageId]]], $context);
        $uid = (int)($handler->copyMappingArray_merged[$target->table][$target->uid] ?? 0);
        if ($uid <= 0) {
            throw new RecordPersistenceException('DataHandler did not resolve the translated record.', 1792305113);
        }
        $saved = $this->reloadAfterCommand($target->table, $uid, $context);
        $schema = $this->targets->schema($target->table, $context);
        $languageField = $schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName();
        if ((int)$saved->getRawRecord()->get($languageField) !== $languageId) {
            throw new RecordPersistenceException('The result does not identify the requested language.', 1792305114);
        }
        return $saved;
    }

    /** @return array{int, int} */
    private function destination(RecordPosition $position, RecordWriteTarget $source, RecordWriteContext $context): array
    {
        if ($position->pageId !== null) {
            $pageId = $this->storagePageId($position->pageId, $context);
            return [$pageId, $pageId];
        }
        $after = $this->targets->fromRecord($position->precedingRecord, $context);
        if ($after->table !== $source->table || $after->uid === $source->uid || $after->languageId !== $source->languageId
            || !$this->targets->schema($source->table, $context)->hasCapability(TcaSchemaCapability::SortByField)) {
            throw new InvalidRecordValueException('Position after requires another sortable record of the same table and language.', 1792305042);
        }
        return [-$after->uid, (int)$after->row['pid']];
    }

    private function storagePageId(int $pageId, RecordWriteContext $context): int
    {
        if ($pageId === 0) {
            return 0;
        }
        $page = $this->targets->byUid('pages', $pageId, $context);
        if ($page->languageId !== 0) {
            throw new InvalidRecordValueException('Storage positions require a default-language page, not a translated page.', 1792305076);
        }
        return $page->uid;
    }

    private function reloadAfterCommand(string $table, int $uid, RecordWriteContext $context): RecordInterface
    {
        try {
            return $this->record($this->targets->byUid($table, $uid, $context));
        } catch (\Throwable $exception) {
            throw new RecordPersistenceException('The command completed but its result could not be reloaded.', 1792305115, [], $exception);
        }
    }

    private function record(RecordWriteTarget $target): RecordInterface
    {
        // A fresh identity map is mandatory; the same logical UID may have an older read-side object.
        return $this->recordFactory->createResolvedRecordFromDatabaseRow($target->table, $target->row, $this->context, new RecordIdentityMap());
    }
}
