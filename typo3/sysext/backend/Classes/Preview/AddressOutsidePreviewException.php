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

namespace TYPO3\CMS\Backend\Preview;

/**
 * The frontend address is on no host of the preview, for example because an event listener
 * changed it to a decoupled frontend. The address opens as it is, without a preview token.
 *
 * @internal
 */
final class AddressOutsidePreviewException extends PreviewAccessDeniedException {}
