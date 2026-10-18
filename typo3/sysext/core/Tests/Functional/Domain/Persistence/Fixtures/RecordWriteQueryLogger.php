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

use Psr\Log\AbstractLogger;

final class RecordWriteQueryLogger extends AbstractLogger
{
    public static int $queries = 0;
    public static int $userQueries = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            self::$queries++;
            if (preg_match('/\bFROM\s+["`]?be_users\b/i', $context['sql'])) {
                self::$userQueries++;
            }
        }
    }
}
