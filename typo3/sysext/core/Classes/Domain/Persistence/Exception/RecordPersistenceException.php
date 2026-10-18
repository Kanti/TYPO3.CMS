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

namespace TYPO3\CMS\Core\Domain\Persistence\Exception;

/**
 * Persistence has been attempted. Some writes may have succeeded; never retry blindly.
 * @internal Experimental record write API.
 */
class RecordPersistenceException extends RecordWriteException
{
    /** @param list<string> $errors */
    public function __construct(string $message, int $code, public readonly array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message . ($errors !== [] ? "\n" . implode("\n", $errors) : ''), $code, $previous);
    }
}
