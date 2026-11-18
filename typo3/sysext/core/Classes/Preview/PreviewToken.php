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
 * A visitor token, the token of the live frame, is valid on every site of the
 * installation, and only the backend uses its editor: he alone renews it. The changelog
 * entry Important-110664-PreviewsOfHiddenPagesWorkOnEveryDomain describes the design.
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
        private bool $visitor = false,
    ) {}

    /**
     * A visitor token shows the pages as visitors see them, and its editor does not become
     * the backend user of the request. Whether a profile shows no more than the visitor
     * view says PreviewProfile::showsVisitorView().
     */
    public function isVisitorWithoutBackendUser(): bool
    {
        return $this->visitor;
    }
}
