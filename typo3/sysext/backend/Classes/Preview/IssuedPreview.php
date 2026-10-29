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

use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Preview\PreviewScope;
use TYPO3\CMS\Core\Preview\PreviewToken;

/**
 * A signed preview token, the frontend address carrying it and the sites
 * and hosts of the preview as they are now: the hosts the frame may
 * navigate to and the origins the backend frame may load.
 *
 * @internal
 */
final readonly class IssuedPreview
{
    public function __construct(
        public PreviewToken $token,
        public UriInterface $uri,
        public PreviewScope $scope,
    ) {}
}
