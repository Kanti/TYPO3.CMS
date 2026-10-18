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

/** @internal Native identifiers and errors, without inferring whether a write succeeded. */
final readonly class RecordWriteDiagnostic
{
    /** @param list<string> $errors */
    public function __construct(
        public RecordDraft $draft,
        public RecordWriteOutcome $outcome,
        public int|string|null $identifier = null,
        public ?int $uid = null,
        public array $errors = [],
    ) {}
}
