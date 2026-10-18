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

final class CommandPositionHook
{
    public function processCmdmap(string $command, string $table, int $id, mixed $value, bool &$commandIsProcessed, DataHandler $handler): void
    {
        if ($command === 'move') {
            $commandIsProcessed = true;
        }
    }

    public function processCmdmap_preProcess(string $command, string $table, int $id, mixed &$value): void
    {
        if ($command === 'copy') {
            $value = 1;
        }
    }
}
