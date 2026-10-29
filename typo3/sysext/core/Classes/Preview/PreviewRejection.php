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

namespace TYPO3\CMS\Core\Preview;

/**
 * Reasons why a request carrying a preview token is refused.
 *
 * @internal
 */
enum PreviewRejection: string
{
    case Expired = 'expired';
    case Invalid = 'invalid';
    case NotInFrame = 'notInFrame';
    case OtherBrowser = 'otherBrowser';
    case OutOfScope = 'outOfScope';
    case UserNotAvailable = 'userNotAvailable';
}
