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

namespace TYPO3\CMS\Frontend\Preview;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Preview\PreviewGroupFilter;
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Decides on the resolved page whether the editor of a token may show it, and which of
 * the simulated frontend user groups the editor may simulate there. PreviewSimulator
 * asks it, and PreviewTokenAuthenticator turns a refusal into its error page.
 *
 * @internal
 */
final readonly class PreviewPageAccess
{
    public function __construct(
        private Context $context,
        private PreviewGroupFilter $groupFilter,
    ) {}

    /**
     * A visitor token simulates no group and shows only what visitors see, so the page
     * needs no check of the editor. For the error page a site shows in place of the
     * requested page, only the check of the right to show the page is left out, as the
     * editor did not request it. The simulated groups are filtered for it as for any
     * other page. The error handler renders it in a sub-request that starts with a fresh
     * context, so the workspace, the date and the hidden content of the token do not
     * apply to it.
     *
     * @return list<int> the simulated groups the editor may simulate on the page
     * @throws PreviewTokenRejectedException if the editor may not show the page
     */
    public function authorize(PreviewToken $token, int $pageId, bool $isErrorPage = false): array
    {
        if ($token->isVisitorWithoutBackendUser()) {
            return [];
        }
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Backend user of the preview is missing', 1794411128, token: $token);
        }
        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId, '', $this->context)->get();
        } catch (RootLineException) {
            $rootline = [];
        }
        $page = reset($rootline);
        if (!is_array($page)) {
            throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page of the preview does not exist', 1794411129, token: $token);
        }
        if (!$isErrorPage && !$user->doesUserHaveAccess($page, Permission::PAGE_SHOW)) {
            throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page is not part of the preview', 1793265774, token: $token);
        }
        return $this->groupFilter->filter($user, $token->profile->groups, $rootline);
    }
}
