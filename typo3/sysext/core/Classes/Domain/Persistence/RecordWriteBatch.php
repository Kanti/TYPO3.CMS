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

    /** @internal Use RecordWriter::batch(). */
    public function __construct(private readonly RecordWriter $writer) {}

    public function create(string $table, int $pid): RecordDraft
    {
        $draft = $this->writer->create($table, $pid);
        $this->drafts[] = $draft;
        return $draft;
    }

    public function edit(RecordInterface $record): RecordDraft
    {
        $draft = $this->writer->edit($record);
        $this->drafts[] = $draft;
        return $draft;
    }

    public function editByUid(string $table, int $uid): RecordDraft
    {
        $draft = $this->writer->editByUid($table, $uid);
        $this->drafts[] = $draft;
        return $draft;
    }

    public function execute(): RecordWriteResult
    {
        return $this->writer->saveAll($this->drafts);
    }
}
