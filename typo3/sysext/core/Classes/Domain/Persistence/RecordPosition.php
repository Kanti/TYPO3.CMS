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

/** @internal Explicit DataHandler destination, without accepting ambiguous negative UIDs. */
final readonly class RecordPosition
{
    private function __construct(public ?int $pageId, public ?RecordInterface $precedingRecord) {}

    public static function onPage(int $pageId): self
    {
        if ($pageId < 0) {
            throw new InvalidRecordValueException('A destination page ID must not be negative.', 1792305037);
        }
        return new self($pageId, null);
    }

    public static function after(RecordInterface $record): self
    {
        return new self(null, $record);
    }
}
