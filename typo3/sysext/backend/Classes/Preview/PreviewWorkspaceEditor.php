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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Preview\PreviewWorkspaceTrait;

/**
 * The editor of a preview token, built again in the workspace of the token to resolve
 * the hosts of the token as the site does. Neither the user record nor the backend
 * session changes.
 *
 * @internal only to be created by the PreviewTokenIssuer
 */
final class PreviewWorkspaceEditor extends BackendUserAuthentication
{
    use PreviewWorkspaceTrait;

    public function __construct(int $previewWorkspace)
    {
        $this->previewWorkspace = $previewWorkspace;
        parent::__construct();
    }
}
