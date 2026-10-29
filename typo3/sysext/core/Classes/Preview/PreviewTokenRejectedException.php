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

use TYPO3\CMS\Core\Exception;

/**
 * A preview the site refuses. The rejection names the reason the error page shows.
 * The token is kept where it was verified, for example an expired one: it names the
 * backend that signed it, so the error page can tell that backend in the frame's
 * parent window to sign a fresh token. A token that could not be verified is null,
 * so the page never talks to an origin it cannot trust.
 *
 * @internal
 */
final class PreviewTokenRejectedException extends Exception
{
    public function __construct(
        public readonly PreviewRejection $rejection,
        string $message,
        int $code,
        ?\Throwable $previous = null,
        public readonly ?PreviewToken $token = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
