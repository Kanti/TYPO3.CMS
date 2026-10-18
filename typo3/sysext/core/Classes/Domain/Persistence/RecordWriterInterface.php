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

/** @internal Experimental Core write API. */
interface RecordWriterInterface
{
    public function create(string $table, int $pid): RecordDraft;

    public function edit(RecordInterface $record): RecordDraft;

    public function editByUid(string $table, int $uid): RecordDraft;

    /** Returns the current record when resolvable, without confirming individual writes. */
    public function save(RecordDraft $draft): ?RecordInterface;

    public function identity(RecordInterface $record): RecordIdentity;

    public function batch(): RecordWriteBatch;

    public function delete(RecordInterface $record): void;

    public function restore(string $table, int $uid): ?RecordInterface;

    public function move(RecordInterface $record, RecordPosition $position): ?RecordInterface;

    public function copy(RecordInterface $record, RecordPosition $position): ?RecordInterface;

    public function localize(RecordInterface $record, int $languageId): ?RecordInterface;

    public function copyToLanguage(RecordInterface $record, int $languageId): ?RecordInterface;

    public function discardWorkspaceVersion(RecordInterface $record): void;
}
