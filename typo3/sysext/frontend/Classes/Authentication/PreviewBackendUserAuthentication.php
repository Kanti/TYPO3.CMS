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

namespace TYPO3\CMS\Frontend\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The editor of a preview token as backend user of a frontend request. Nothing
 * of it outlives the request: there is no session and no cookie, and neither the
 * user settings nor the workspace are written back to the user record. The
 * workspace is the one of the token; a workspace the editor may not enter is
 * refused rather than replaced by another one. A preview only shows: the editor has
 * no right to change tables, pages or files, see also PreviewDataHandlerGuard. As for
 * a backend login, the admin panel is available; it stores its settings through the
 * backend around the frame.
 *
 * @internal only to be created by the PreviewBackendUserInitializer
 */
final class PreviewBackendUserAuthentication extends FrontendBackendUserAuthentication
{
    public function __construct(
        private readonly int $previewWorkspace,
    ) {
        $this->dontSetCookie = true;
        parent::__construct();
        // Session data, such as form protection tokens, stays in memory
        $this->initializeUserSessionManager();
    }

    /**
     * @param array<string, mixed> $record
     */
    public function initializeFromRecord(array $record): void
    {
        $record['workspace_id'] = $this->previewWorkspace;
        $this->user = $record;
        $this->unpack_uc();
    }

    /**
     * The editor is known from the token: a session cookie or login data of the
     * request must not replace it.
     */
    public function start(ServerRequestInterface $request)
    {
        // do nothing
    }

    public function checkAuthentication(ServerRequestInterface $request)
    {
        // do nothing
    }

    public function getOriginalUserIdWhenInSwitchUserMode(): ?int
    {
        return null;
    }

    /**
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

    public function writeUC(): void
    {
        // do nothing
    }

    /**
     * There is no session to renew: the token is the only credential.
     */
    public function enforceNewSessionId()
    {
        // do nothing
    }

    /**
     * Neither the editor of the token nor a backend session of the browser is logged off.
     */
    public function logoff()
    {
        // do nothing
    }

    public function setAndSaveSessionData($key, $data)
    {
        $this->setSessionData($key, $data);
    }

    public function check($type, $value)
    {
        if ($type === 'tables_modify') {
            return false;
        }
        return parent::check($type, $value);
    }

    public function doesUserHaveAccess($row, $perms, bool $useDeleteClause = true)
    {
        // Only the right to show a page
        if (((int)$perms & ~Permission::PAGE_SHOW) !== 0) {
            return false;
        }
        return parent::doesUserHaveAccess($row, $perms, $useDeleteClause);
    }

    public function calcPerms($row, bool $useDeleteClause = true)
    {
        return parent::calcPerms($row, $useDeleteClause) & Permission::PAGE_SHOW;
    }

    public function getFilePermissions(): array
    {
        return array_map(static fn(): bool => false, parent::getFilePermissions());
    }
}
