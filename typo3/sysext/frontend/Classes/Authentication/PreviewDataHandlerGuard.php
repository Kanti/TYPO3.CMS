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

namespace TYPO3\CMS\Frontend\Authentication;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Stops the DataHandler before it writes anything for the backend user of a preview.
 * PreviewBackendUserAuthentication already denies the rights to change tables and
 * pages, but the DataHandler grants admins every change without asking for them.
 * Code with a backend user of its own is not affected.
 *
 * @internal
 */
final readonly class PreviewDataHandlerGuard
{
    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        if (!$dataHandler->BE_USER instanceof PreviewBackendUserAuthentication) {
            return;
        }
        if ($dataHandler->datamap === []) {
            return;
        }
        throw new \RuntimeException('The backend user of a preview may not change records.', 1794406762);
    }

    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        if (!$dataHandler->BE_USER instanceof PreviewBackendUserAuthentication) {
            return;
        }
        if ($dataHandler->cmdmap === []) {
            return;
        }
        throw new \RuntimeException('The backend user of a preview may not run commands on records.', 1794406763);
    }
}
