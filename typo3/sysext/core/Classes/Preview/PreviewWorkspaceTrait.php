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
 * The workspace of the editor of a preview token, for a backend user class: the
 * workspace of the token, entered for this object only. It is never written to
 * the user record. A workspace the editor may not enter is refused rather than
 * replaced by another one. The site builds the editor of a token with it on every
 * request. The backend builds the editor with it to resolve the hosts of a token
 * for a workspace the editor is not in now, so both get the same result.
 *
 * @internal
 */
trait PreviewWorkspaceTrait
{
    private readonly int $previewWorkspace;

    /**
     * fetchGroupData() calls it with the workspace of the user record
     *
     * @throws PreviewTokenRejectedException if the editor may not enter the workspace of the token
     */
    public function setWorkspace($workspaceId)
    {
        $this->checkWorkspaceCurrent_cache = null;
        if ((int)$workspaceId !== $this->previewWorkspace) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Only the workspace of the preview may be set', 1794239501);
        }
        if (!$this->setTemporaryWorkspace($this->previewWorkspace)) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Workspace of the preview is not accessible', 1794411124);
        }
    }
}
