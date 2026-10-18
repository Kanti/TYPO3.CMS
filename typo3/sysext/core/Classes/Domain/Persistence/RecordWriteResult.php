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

/** @internal Results retain the draft objects, avoiding object-ID reuse. */
final readonly class RecordWriteResult
{
    /** @param array<int, array{RecordDraft, ?RecordInterface}> $records */
    public function __construct(private array $records, public ?RecordWriteDiagnostics $diagnostics = null) {}

    /** A fresh read when a native identifier is available; not a write confirmation. */
    public function recordFor(RecordDraft $draft): ?RecordInterface
    {
        $entry = $this->records[spl_object_id($draft)] ?? null;
        if ($entry === null || $entry[0] !== $draft) {
            throw new InvalidRecordValueException('This draft is not part of the result.', 1792305056);
        }
        return $entry[1];
    }
}
