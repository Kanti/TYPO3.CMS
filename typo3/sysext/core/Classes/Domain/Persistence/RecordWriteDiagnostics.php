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

/** @internal Structured batch reconciliation; retains drafts to prevent object-ID reuse. */
final readonly class RecordWriteDiagnostics
{
    /** @param array<int, RecordWriteDiagnostic> $entries */
    public function __construct(private array $entries) {}

    public function forDraft(RecordDraft $draft): RecordWriteDiagnostic
    {
        $entry = $this->entries[spl_object_id($draft)] ?? null;
        if ($entry === null || $entry->draft !== $draft) {
            throw new InvalidRecordValueException('The draft does not belong to these diagnostics.', 1792310020);
        }
        return $entry;
    }

    /** @return list<RecordWriteDiagnostic> */
    public function all(): array
    {
        return array_values($this->entries);
    }

    public function isValid(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->errors !== []) {
                return false;
            }
        }
        return true;
    }
}
