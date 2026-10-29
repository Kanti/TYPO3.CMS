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
 * A signed preview token and the claims it carries. It names the editor who opened
 * the preview; the site builds this editor again on every request, so rights changed
 * in the meantime apply, also to the sites and hosts the token is valid on, see
 * PreviewScopeResolver. A visitor token shows the pages as a visitor sees them, see
 * PreviewProfile::isVisitorView(): its editor only decides where it is valid and
 * does not become the backend user of the request.
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
