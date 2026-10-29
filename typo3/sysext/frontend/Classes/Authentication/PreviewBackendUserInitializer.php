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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;

/**
 * Makes the editor of a preview token the backend user of the frontend
 * request: stateless, without session or cookie, and built again on every
 * request, so the rights of the editor apply as they are now.
 *
 * @internal
 */
final readonly class PreviewBackendUserInitializer
{
    public function __construct(
        private Context $context,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @throws PreviewTokenRejectedException if the editor may not see the preview
     */
    public function initialize(PreviewToken $token): BackendUserAuthentication
    {
        if ($token->userId === null) {
            throw new \LogicException('A visitor token builds no backend user of the request.', 1794239502);
        }
        // Only BackendUserAuthenticator sets one, and it runs after PreviewAccess
        if (isset($GLOBALS['BE_USER'])) {
            throw new \LogicException('A backend user is already set before the preview builds the editor of its token.', 1794413652);
        }
        $user = $this->build($token);
        $GLOBALS['BE_USER'] = $user;
        $this->context->setAspect('backend.user', new UserAspect($user));
        $this->context->setAspect('workspace', new WorkspaceAspect($user->workspace));
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($user);
        return $user;
    }

    /**
     * The editor who opened a visitor token, only to resolve where the token is valid:
     * it does not become the backend user of the request.
     *
     * @throws PreviewTokenRejectedException if the editor is no longer available
     */
    public function createEditorOfVisitorToken(PreviewToken $token): BackendUserAuthentication
    {
        if (!$token->visitor) {
            throw new \LogicException('Only a visitor token has an editor that is not the backend user.', 1794385524);
        }
        return $this->build($token);
    }

    /**
     * @throws PreviewTokenRejectedException
     */
    private function build(PreviewToken $token): PreviewBackendUserAuthentication
    {
        $user = new PreviewBackendUserAuthentication($token->profile->workspace);
        $record = $user->getRawUserByUid($token->editorId);
        if (!is_array($record)) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Editor of the preview token does not exist, is disabled or expired', 1794224123, null, $token);
        }
        $user->initializeFromRecord($record);
        if ((int)($GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'] ?? 0) < 0) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Backend is locked', 1794239503, null, $token);
        }
        if (!$user->isUserAllowedToLogin()) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Backend is locked for the editor of the preview token', 1794411125, null, $token);
        }
        // TSconfig conditions are evaluated in the workspace of the preview
        $this->context->setAspect('workspace', new WorkspaceAspect($token->profile->workspace));
        try {
            // Resolves groups, TSconfig and mounts, and enters the workspace of the token
            $user->fetchGroupData();
        } catch (PreviewTokenRejectedException $exception) {
            throw new PreviewTokenRejectedException($exception->rejection, $exception->getMessage(), 1794224122, $exception, $token);
        }
        $user->backendSetUC();
        return $user;
    }
}
