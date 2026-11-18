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
 * How a preview token shows the pages, signed into the token by the backend. The site
 * exposes it in the property "mode" of the aspect "frontend.preview": a request without
 * a preview token is always in the mode view.
 */
enum PreviewMode: string
{
    /**
     * The pages as the simulation of the token shows them, the only mode of a visitor token
     */
    case View = 'view';

    /**
     * The pages in the frame of the editing interface, only for the editor of a token
     */
    case Edit = 'edit';
}
