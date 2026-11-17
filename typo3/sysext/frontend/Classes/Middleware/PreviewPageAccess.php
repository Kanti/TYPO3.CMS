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

namespace TYPO3\CMS\Frontend\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Preview\PreviewGroupFilter;
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Preview\PreviewNavigationScript;

/**
 * Refuses a preview of a page the editor of the token may not show, as soon as
 * the page is resolved, and decides which of the simulated frontend user groups the
 * editor may simulate on this page. PreviewAccess turns the refusal into its error
 * page; PreviewNavigationScript adds the navigation script to the rendered page.
 *
 * @internal
 */
final readonly class PreviewPageAccess implements MiddlewareInterface
{
    public const GROUPS_ATTRIBUTE = 'frontend.preview.groups';

    public function __construct(
        private Context $context,
        private PreviewGroupFilter $groupFilter,
        private PreviewNavigationScript $navigationScript,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getAttribute(PreviewAccess::ATTRIBUTE);
        $pageArguments = $request->getAttribute('routing');
        if (!$token instanceof PreviewToken) {
            return $handler->handle($request);
        }
        if (!$pageArguments instanceof PageArguments) {
            return $handler->handle($request);
        }
        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageArguments->getPageId(), '', $this->context)->get();
        } catch (RootLineException) {
            $rootline = [];
        }
        $groups = [];
        // A refusal leaves the stack through PreviewAccess, which answers with the error page
        if (!$token->isVisitorWithoutBackendUser()) {
            $user = $GLOBALS['BE_USER'] ?? null;
            if (!$user instanceof BackendUserAuthentication) {
                throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Backend user of the preview is missing', 1794411128, token: $token);
            }
            $page = reset($rootline);
            if (!is_array($page)) {
                throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page of the preview does not exist', 1794411129, token: $token);
            }
            if (!$user->doesUserHaveAccess($page, Permission::PAGE_SHOW)) {
                throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page is not part of the preview', 1793265774, token: $token);
            }
            $groups = $this->groupFilter->filter($user, $token->profile->groups, $rootline);
        }
        $request = $request->withAttribute(self::GROUPS_ATTRIBUTE, $groups);
        return $this->navigationScript->addToPage($request, $handler, $token, $pageArguments->getPageId());
    }
}
