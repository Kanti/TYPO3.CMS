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

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Persistence\Exception\DataHandlerExecutionException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordNotFoundException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordWriteException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;

/**
 * TCA record writes through DataHandler, without mutating read-side records.
 * @internal Experimental, matching the stability of the Core Record API.
 */
#[Autoconfigure(public: true), AsAlias(RecordWriterInterface::class, public: true)]
final readonly class RecordWriter implements RecordWriterInterface
{
    public function __construct(
        private Context $context,
        private RecordWriteTargetResolver $targets,
        private RecordFieldNormalizer $normalizer,
        private DataHandlerRecordWriter $adapter,
        private RecordFactory $recordFactory,
        private RecordRelationResolver $relations,
        private RecordDraftValueResolver $draftValues,
        private RecordWriteContextFactory $contexts,
    ) {}

    public function create(string $table, int $pid): RecordDraft
    {
        return $this->createInContext($table, $pid, $this->contexts->capture($this->context));
    }

    /** @internal A batch owns one context and resolves identical storage positions once. */
    public function createInContext(string $table, int $pid, RecordWriteContext $context, ?int $resolvedPid = null): RecordDraft
    {
        $context->assertCurrent($this->context);
        $this->targets->schema($table, $context);
        if ($pid < 0) {
            throw new InvalidRecordValueException('Creation requires a non-negative page ID.', 1792305035);
        }
        return new RecordDraft($table, $resolvedPid ?? $this->storagePageId($pid, $context), $context, null, $this->relations, $this->draftValues);
    }

    /** @internal A fresh boundary snapshot; no global identity remains bound. */
    public function batchContext(): RecordWriteContext
    {
        return $this->contexts->capture($this->context, true);
    }

    public function edit(RecordInterface $record): RecordDraft
    {
        return $this->editInContext($record, $this->contexts->capture($this->context, true));
    }

    /** @internal */
    public function editInContext(RecordInterface $record, RecordWriteContext $context): RecordDraft
    {
        $context->assertCurrent($this->context);
        return $context->run(function () use ($context, $record) {
            $target = $this->targets->fromRecord($record, $context);
            return new RecordDraft($target->table, (int)$target->row['pid'], $context, $target, $this->relations, $this->draftValues);
        });
    }

    public function editByUid(string $table, int $uid): RecordDraft
    {
        return $this->editByUidInContext($table, $uid, $this->contexts->capture($this->context, true));
    }

    /** @internal */
    public function editByUidInContext(string $table, int $uid, RecordWriteContext $context): RecordDraft
    {
        $context->assertCurrent($this->context);
        return $context->run(function () use ($context, $table, $uid) {
            $target = $this->targets->byUid($table, $uid, $context);
            return new RecordDraft($table, (int)$target->row['pid'], $context, $target, $this->relations, $this->draftValues);
        });
    }

    public function save(RecordDraft $draft): ?RecordInterface
    {
        return $this->saveAll([$draft])->recordFor($draft);
    }

    public function identity(RecordInterface $record): RecordIdentity
    {
        $context = $this->contexts->capture($this->context, true);
        return $context->run(function () use ($record, $context) {
            $target = $this->targets->fromRecord($record, $context);
            return $this->targetIdentity($target, $context);
        });
    }

    private function targetIdentity(RecordWriteTarget $target, RecordWriteContext $context): RecordIdentity
    {
        $schema = $this->targets->schema($target->table, $context);
        $origin = null;
        if ($schema->isLanguageAware()) {
            $originField = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
            $origin = (int)($target->row[$originField] ?? 0) ?: null;
        }
        return new RecordIdentity($target->table, $target->uid, (int)$target->row['uid'], $target->languageId, (int)($target->row['t3ver_wsid'] ?? 0), $origin);
    }

    public function batch(): RecordWriteBatch
    {
        return new RecordWriteBatch($this);
    }

    /** @internal @param list<RecordDraft> $drafts */
    public function saveAll(array $drafts): RecordWriteResult
    {
        if ($drafts === []) {
            return new RecordWriteResult([]);
        }
        $this->contexts->validate($drafts, $this->context);
        return $drafts[0]->context->run(fn() => $this->executeDrafts($drafts));
    }

    /** @return array{array<int, int|string>, array<int, array>, array} */
    private function prepareDrafts(array $drafts): array
    {
        $identifiers = [];
        $targets = [];
        $values = [];
        $data = [];
        $changesByDraft = [];
        $observedByDraft = [];
        $checkedPages = [];
        foreach ($drafts as $draft) {
            $draft->assertEditable();
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
            [$changesByDraft[$key], $observedByDraft[$key]] = $this->relations->prepareChanges($draft);
            $values[$key] = $this->normalizer->changes($draft, $schema, $identifiers, $currentTarget, $changesByDraft[$key]);
            if ($currentTarget !== null) {
                if ($currentTarget->languageId !== $draft->target->languageId) {
                    throw new InvalidRecordValueException('The record language changed since draft creation.', 1792305036);
                }
                // Best-effort no-op detection, not atomic optimistic locking.
                foreach ($values[$key] as $name => $value) {
                    if ($schema->getField($name) instanceof RelationalFieldTypeInterface) {
                        if ($this->relations->unchanged($draft, $name, $changesByDraft[$key][$name], $observedByDraft[$key][$name])) {
                            unset($values[$key][$name]);
                        }
                        continue;
                    }
                    $original = $currentTarget->row[$name] ?? null;
                    if ($value === $original || (is_scalar($value) && $original !== null && (string)$value === (string)$original)) {
                        unset($values[$key][$name]);
                    }
                }
            } else {
                if ($draft->pid > 0 && !isset($checkedPages[$draft->pid])) {
                    $this->targets->byUid('pages', $draft->pid, $draft->context);
                    $checkedPages[$draft->pid] = true;
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
        $this->relations->assertNoInlineCycles($drafts, $changesByDraft);
        foreach ($drafts as $draft) {
            foreach ($this->relations->detachments($draft, $changesByDraft[spl_object_id($draft)], $observedByDraft[spl_object_id($draft)]) as $table => $rows) {
                foreach ($rows as $uid => $fields) {
                    if (isset($data[$table][$uid]) || isset($targets[$table . ':' . $uid])) {
                        throw new InvalidRecordValueException('Inline detachment conflicts with another draft.', 1792305059);
                    }
                    $data[$table][$uid] = $fields;
                }
            }
        }
        return [$identifiers, $values, $data];
    }

    private function executeDrafts(array $drafts): RecordWriteResult
    {
        [$identifiers, $values, $data] = $this->prepareDrafts($drafts);
        foreach ($drafts as $draft) {
            $draft->consume();
        }
        try {
            $handler = $data !== [] ? $this->adapter->execute($data, [], $drafts[0]->context) : null;
        } catch (DataHandlerExecutionException $exception) {
            $exception->diagnostics = $this->diagnostics($drafts, $identifiers, $exception->newRecordIds, $exception->errors ?: [$exception->getMessage()]);
            throw $exception;
        }
        $diagnostics = $this->diagnostics($drafts, $identifiers, $handler->substNEWwithIDs ?? []);
        $records = [];
        try {
            foreach ($drafts as $draft) {
                $key = spl_object_id($draft);
                $uid = $diagnostics->forDraft($draft)->uid;
                $record = $uid !== null ? $this->reload($draft->table, $uid, $draft->context) : null;
                // Do not return a fallback in place of an unresolved language/workspace target.
                $schema = $this->targets->schema($draft->table, $draft->context);
                $language = $schema->isLanguageAware() && $record !== null
                    ? (int)$record->getRawRecord()->get($schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()) : 0;
                if ($record !== null && ($language !== ($draft->target->languageId ?? 0)
                    || ($draft->context->workspaceId > 0 && $values[$key] !== [] && (int)($record->getRawRecord()->toArray()['t3ver_wsid'] ?? 0) !== $draft->context->workspaceId))) {
                    $record = null;
                }
                $records[$key] = [$draft, $record];
            }
        } catch (\Throwable $exception) {
            $failure = new RecordPersistenceException('DataHandler returned, but the current records could not be read.', 1792305107, [], $exception);
            $failure->diagnostics = $diagnostics;
            throw $failure;
        }
        return new RecordWriteResult($records, $diagnostics);
    }

    /** @internal Preflight validation does not consume drafts or execute DataHandler. */
    public function validateAll(array $drafts): RecordWriteDiagnostics
    {
        $identifiers = [];
        foreach ($drafts as $draft) {
            $identifiers[spl_object_id($draft)] = $draft->target->uid ?? 'NEW' . spl_object_id($draft);
        }
        $entries = [];
        $contextError = null;
        try {
            $this->contexts->validate($drafts, $this->context);
        } catch (RecordWriteException $exception) {
            $contextError = $exception;
        }
        foreach ($drafts as $draft) {
            $errors = [];
            try {
                if ($contextError !== null) {
                    throw $contextError;
                }
                $draft->assertEditable();
                $draft->context->run(function () use ($draft, $identifiers) {
                    $target = $draft->target !== null ? $this->targets->byUid($draft->table, $draft->target->uid, $draft->context) : null;
                    [$changes] = $this->relations->prepareChanges($draft);
                    $this->normalizer->changes($draft, $this->targets->schema($draft->table, $draft->context), $identifiers, $target, $changes);
                });
            } catch (RecordWriteException $exception) {
                $errors[] = $exception->getMessage();
            }
            $entries[spl_object_id($draft)] = new RecordWriteDiagnostic($draft, RecordWriteOutcome::NotAttempted, errors: $errors);
        }
        $diagnostics = new RecordWriteDiagnostics($entries);
        if ($drafts !== [] && $diagnostics->isValid()) {
            try {
                $drafts[0]->context->run(fn() => $this->prepareDrafts($drafts));
            } catch (RecordWriteException $exception) {
                foreach ($drafts as $draft) {
                    $entries[spl_object_id($draft)] = new RecordWriteDiagnostic($draft, RecordWriteOutcome::NotAttempted, errors: [$exception->getMessage()]);
                }
                $diagnostics = new RecordWriteDiagnostics($entries);
            }
        }
        return $diagnostics;
    }

    private function diagnostics(array $drafts, array $identifiers, array $newIds, array $errors = []): RecordWriteDiagnostics
    {
        $entries = [];
        foreach ($drafts as $draft) {
            $key = spl_object_id($draft);
            $entries[$key] = new RecordWriteDiagnostic(
                $draft,
                RecordWriteOutcome::Unknown,
                $identifiers[$key],
                $draft->target->uid ?? ($newIds[$identifiers[$key]] ?? null),
                $errors,
            );
        }
        return new RecordWriteDiagnostics($entries);
    }

    public function delete(RecordInterface $record): void
    {
        $context = $this->contexts->capture($this->context, true);
        $context->run(function () use ($context, $record) {
            $target = $this->targets->fromRecord($record, $context);
            $this->adapter->execute([], [$target->table => [$target->uid => ['delete' => 1]]], $context);
        });
    }

    public function restore(string $table, int $uid): ?RecordInterface
    {
        $context = $this->contexts->capture($this->context, true);
        return $context->run(function () use ($context, $table, $uid) {
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
            return $this->reload($table, $target->uid, $context);
        });
    }

    public function move(RecordInterface $record, RecordPosition $position): ?RecordInterface
    {
        $context = $this->contexts->capture($this->context, true);
        return $context->run(function () use ($context, $record, $position) {
            $target = $this->targets->fromRecord($record, $context);
            [$destination, $pid] = $this->destination($position, $target, $context);
            $this->adapter->execute([], [$target->table => [$target->uid => ['move' => $destination]]], $context);
            $saved = $this->reload($target->table, $target->uid, $context);
            if ($saved !== null && $saved->getPid() !== $pid) {
                throw new RecordPersistenceException('DataHandler did not move the record to the requested page.', 1792305109);
            }
            if ($saved !== null && $destination < 0) {
                $this->assertRelativePosition($saved, -$destination, $context);
            }
            return $saved;
        });
    }

    public function copy(RecordInterface $record, RecordPosition $position): ?RecordInterface
    {
        $context = $this->contexts->capture($this->context, true);
        return $context->run(function () use ($context, $record, $position) {
            $target = $this->targets->fromRecord($record, $context);
            [$destination, $pid] = $this->destination($position, $target, $context);
            $handler = $this->adapter->execute([], [$target->table => [$target->uid => ['copy' => $destination]]], $context);
            $uid = (int)($handler->copyMappingArray_merged[$target->table][$target->uid] ?? 0);
            if ($uid <= 0 || $uid === $target->uid) {
                return null;
            }
            $saved = $this->reload($target->table, $uid, $context);
            if ($saved !== null && $saved->getPid() !== $pid) {
                throw new RecordPersistenceException('The copy was not created on the requested page.', 1792305111);
            }
            if ($saved !== null && $destination < 0) {
                $this->assertRelativePosition($saved, -$destination, $context);
            }
            return $saved;
        });
    }

    public function localize(RecordInterface $record, int $languageId): ?RecordInterface
    {
        return $this->languageCommand($record, $languageId, 'localize');
    }

    public function copyToLanguage(RecordInterface $record, int $languageId): ?RecordInterface
    {
        return $this->languageCommand($record, $languageId, 'copyToLanguage');
    }

    public function discardWorkspaceVersion(RecordInterface $record): void
    {
        $context = $this->contexts->capture($this->context, true);
        $context->run(function () use ($context, $record) {
            $target = $this->targets->fromRecord($record, $context);
            if ($context->workspaceId <= 0 || (int)($target->row['t3ver_wsid'] ?? 0) !== $context->workspaceId) {
                throw new InvalidRecordValueException('Discard requires a version in the current workspace.', 1792305040);
            }
            $this->adapter->execute([], [$target->table => [$target->uid => ['discard' => 1]]], $context);
        });
    }

    private function languageCommand(RecordInterface $record, int $languageId, string $command): ?RecordInterface
    {
        $context = $this->contexts->capture($this->context, true);
        return $context->run(function () use ($context, $record, $languageId, $command) {
            $target = $this->targets->fromRecord($record, $context);
            if ($languageId <= 0 || $target->languageId !== 0 || !$this->targets->schema($target->table, $context)->isLanguageAware()) {
                throw new InvalidRecordValueException('Localization requires a default-language source and a positive target language.', 1792305041);
            }
            $handler = $this->adapter->execute([], [$target->table => [$target->uid => [$command => $languageId]]], $context);
            $uid = (int)($handler->copyMappingArray_merged[$target->table][$target->uid] ?? 0);
            if ($uid <= 0) {
                return null;
            }
            $saved = $this->reload($target->table, $uid, $context);
            $schema = $this->targets->schema($target->table, $context);
            $languageField = $schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName();
            if ($saved !== null && (int)$saved->getRawRecord()->get($languageField) !== $languageId) {
                throw new RecordPersistenceException('The result does not identify the requested language.', 1792305114);
            }
            return $saved;
        });
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

    private function assertRelativePosition(RecordInterface $record, int $precedingUid, RecordWriteContext $context): void
    {
        $preceding = $this->targets->byUid($record->getMainType(), $precedingUid, $context);
        $field = $this->targets->schema($preceding->table, $context)->getCapability(TcaSchemaCapability::SortByField)->getFieldName();
        if ($record->getPid() !== (int)$preceding->row['pid'] || (int)$record->getRawRecord()->get($field) <= (int)$preceding->row[$field]) {
            throw new RecordPersistenceException('The record is not at the requested relative position.', 1792350001);
        }
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

    private function reload(string $table, int $uid, RecordWriteContext $context): ?RecordInterface
    {
        try {
            return $this->record($this->targets->byUid($table, $uid, $context), $context);
        } catch (RecordNotFoundException) {
            return null;
        }
    }

    private function record(RecordWriteTarget $target, RecordWriteContext $context): RecordInterface
    {
        // A fresh identity map is mandatory; the same logical UID may have an older read-side object.
        return $this->recordFactory->createResolvedRecordFromDatabaseRow($target->table, $target->row, $context->readingContext(), new RecordIdentityMap());
    }
}
