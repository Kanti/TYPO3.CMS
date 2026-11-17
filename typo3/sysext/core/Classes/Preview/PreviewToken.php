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
 * and the simulation to show. The editor of a token is the backend user of the request.
 * A token that shows live, the visitor token of the live frame, only uses its editor to
 * decide where it is valid. The changelog entry
 * Important-110664-PreviewsOfHiddenPagesWorkOnEveryDomain describes the design.
 *
 * @internal
 */
final readonly class PreviewToken
{
    public function __construct(
        public string $jwt,
        public PreviewProfile $profile,
        public string $backendOrigin,
        public int $expires,
        public int $editorId,
        private bool $showLive = false,
    ) {}

    /**
     * A token that shows live shows the pages as visitors see them, and its editor does
     * not become the backend user of the request. Whether a profile shows no more than
     * the live view says PreviewProfile::showsLiveView().
     */
    public function showsLive(): bool
    {
        return $this->showLive;
    }
}
