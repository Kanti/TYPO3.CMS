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

/**
 * A logical UID identifies the concrete language record in Live; physicalUid identifies its
 * actual database row. New workspace records have no Live row yet, even when both UIDs match.
 * @internal Experimental Core write API.
 */
final readonly class RecordIdentity
{
    public function __construct(
        public string $table,
        public int $logicalUid,
        public int $physicalUid,
        public int $languageId,
        public int $workspaceId,
        public ?int $defaultLanguageUid = null,
    ) {}
}
