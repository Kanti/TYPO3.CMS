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

/** @internal Explicit reference to an existing record, resolved in the draft workspace. */
final readonly class RecordReference
{
    public function __construct(public string $table, public int $uid)
    {
        if ($table === '' || $uid <= 0) {
            throw new InvalidRecordValueException('A record reference requires a table and positive UID.', 1792305043);
        }
    }

    public function key(): string
    {
        return $this->table . ':' . $this->uid;
    }
}
