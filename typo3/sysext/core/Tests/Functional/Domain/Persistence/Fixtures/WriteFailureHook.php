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

namespace TYPO3\CMS\Core\Tests\Functional\Domain\Persistence\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;

final class WriteFailureHook
{
    public function processDatamap_postProcessFieldArray(string $status, string $table, int|string $id, array &$fields, DataHandler $handler): void
    {
        if (($fields['title'] ?? '') === 'Remove all fields') {
            $fields = [];
        }
        if (str_ends_with($fields['title'] ?? '', 'SQL without logging')) {
            $handler->enableLogging = false;
        }
        if (($fields['title'] ?? '') === 'Invalid SQL without logging') {
            $fields['nonexistent_record_write_column'] = 'Force a real SQL error';
        }
    }
}
