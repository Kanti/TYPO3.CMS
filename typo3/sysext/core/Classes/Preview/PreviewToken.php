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
 * A signed preview token and the claims it carries: the editor who opened the preview
 * and the simulation to show. A visitor token names its editor only to decide where it
 * is valid. The changelog entry Important-110664-PreviewsOfHiddenPagesWorkOnEveryDomain
 * describes the design.
 *
 * @internal
 */
final readonly class PreviewToken
{
    /**
     * The backend user of the request, null for a visitor token
     */
    public ?int $userId;

    public function __construct(
        public string $jwt,
        public PreviewProfile $profile,
        public string $backendOrigin,
        public int $expires,
        public int $editorId,
        public bool $visitor = false,
    ) {
        $this->userId = $visitor ? null : $editorId;
    }
}
