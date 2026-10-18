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

use TYPO3\CMS\Core\Domain\RecordInterface;

/** @internal Compatible drafts share a DataHandler execution; batches are not transactions. */
final class RecordWriteBatch
{
    /** @var list<RecordDraft> */
    private array $drafts = [];

    private ?RecordWriteContext $context = null;
    private array $storagePids = [];

    /** @internal Use RecordWriter::batch(). */
    public function __construct(private readonly RecordWriter $writer) {}

    private function context(): RecordWriteContext
    {
        return $this->context ??= $this->writer->batchContext();
    }

    public function create(string $table, int $pid): RecordDraft
    {
        $draft = $this->writer->createInContext($table, $pid, $this->context(), $this->storagePids[$pid] ?? null);
        $this->storagePids[$pid] = $draft->pid;
        $this->drafts[] = $draft;
        return $draft;
    }

    public function edit(RecordInterface $record): RecordDraft
    {
        $draft = $this->writer->editInContext($record, $this->context());
        $this->drafts[] = $draft;
        return $draft;
    }

    public function editByUid(string $table, int $uid): RecordDraft
    {
        $draft = $this->writer->editByUidInContext($table, $uid, $this->context());
        $this->drafts[] = $draft;
        return $draft;
    }

    public function validate(): RecordWriteDiagnostics
    {
        return $this->writer->validateAll($this->drafts);
    }

    public function remove(RecordDraft $draft): void
    {
        $draft->assertEditable();
        $this->drafts = array_values(array_filter($this->drafts, static fn(RecordDraft $candidate): bool => $candidate !== $draft));
    }

    public function execute(): RecordWriteResult
    {
        return $this->writer->saveAll($this->drafts);
    }
}
