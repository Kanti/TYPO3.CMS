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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Domain\DateTimeFactory;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Schema\LanguageMarker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Controller\ErrorController;
use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;

/**
 * Middleware for handling preview settings
 * used when simulating / previewing pages or content through query params when
 * previewing access or time restricted content via for example backend preview links
 */
readonly class PreviewSimulator implements MiddlewareInterface
{
    public function __construct(
        protected Context $context,
        protected PageRepository $pageRepository,
    ) {}

    /**
     * Evaluates preview settings if a backend user is logged in
     *
     * @throws \Exception
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A preview token brings date, visibility and workspace along, see PreviewAccess
        $previewToken = $request->getAttribute(PreviewAccess::ATTRIBUTE);
        if ($previewToken instanceof PreviewToken) {
            if (!$previewToken->profile->loggedIn) {
                // An editor keeps a frontend login of the frame, as with a backend login. A token without
                // an editor shows the page as a visitor sees it, who is not logged in: "-1" applies
                if ($previewToken->userId === null) {
                    $this->context->setAspect('frontend.user', new UserAspect(null, [0, -1]));
                }
            } else {
                // Only the groups the editor may simulate on this page, see PreviewAccessScope
                $groups = $request->getAttribute(PreviewAccessScope::GROUPS_ATTRIBUTE, []);
                $this->replaceUserGroups($request, $groups);
                if ($groups === []) {
                    // A login without any group still counts as login: "-2" applies, "-1" does not
                    $this->context->setAspect('frontend.user', new UserAspect($request->getAttribute('frontend.user'), [0, -2]));
                }
            }
            if ($this->editorSeesHiddenPage($previewToken, $request)) {
                $this->context->setAspect('visibility', $this->context->getAspect('visibility')->withIncludeHiddenPages(true));
                $this->context->setAspect('frontend.preview', new PreviewAspect(true));
            }
            return $handler->handle($request);
        }
        $isLoggedIn = $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
        $isOfflineWorkspace = $this->context->getPropertyFromAspect('workspace', 'isOffline', false);
        // When previewing a workspace with the preview link, the PreviewUserAuthentication is NOT marked as
        // "isLoggedIn" as it does not have a valid user ID. For this reason, we also check if the Workspace is offline. See WorkspacePreview middleware
        if ($isLoggedIn || $isOfflineWorkspace) {
            $pageArguments = $request->getAttribute('routing', null);
            if (!$pageArguments instanceof PageArguments) {
                return GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                    $request,
                    'Page Arguments could not be resolved',
                    ['code' => PageAccessFailureReasons::INVALID_PAGE_ARGUMENTS]
                );
            }
            $visibilityAspect = $this->context->getAspect('visibility');
            // The preview flag is set if the current page turns out to be hidden
            $showHiddenPages = $this->checkIfPageIsHidden($pageArguments->getPageId(), $request);
            $rootlineRequiresPreviewFlag = $this->checkIfRootlineRequiresPreview($pageArguments->getPageId());
            $simulatingDate = $this->simulateDate($request);
            $simulatingGroup = $this->simulateUserGroup($request);
            $showHiddenRecords = $visibilityAspect->includeHidden();
            $isPreview = $simulatingDate || $simulatingGroup || $showHiddenRecords || $showHiddenPages || $isOfflineWorkspace || $rootlineRequiresPreviewFlag;
            if ($this->context->hasAspect('frontend.preview')) {
                /** @var PreviewAspect $previewAspect */
                $previewAspect = $this->context->getAspect('frontend.preview');
                $isPreview = $previewAspect->isPreview() || $isPreview;
            }
            $this->context->setAspect('frontend.preview', new PreviewAspect($isPreview));

            if ($showHiddenPages || $rootlineRequiresPreviewFlag) {
                $this->context->setAspect('visibility', $visibilityAspect->withIncludeHiddenPages(true));
            }
        }

        return $handler->handle($request);
    }

    /**
     * Evaluate if the "extendToSubpages" flag was set on any of the previous ancestor pages,
     * but be sure to not check for the current page itself.
     */
    protected function checkIfRootlineRequiresPreview(int $pageId): bool
    {
        $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $pageId, '', $this->context);
        $groupRestricted = false;
        $timeRestricted = false;
        $hidden = false;
        try {
            $rootLine = $rootlineUtility->get();

            // Remove the current page from the rootline
            array_shift($rootLine);
            foreach ($rootLine as $page) {
                // Skip root node and pages which do not define extendToSubpages
                if ((int)($page['uid'] ?? 0) === 0 || !(bool)($page['extendToSubpages'] ?? false)) {
                    continue;
                }
                $groupRestricted = (bool)(string)($page['fe_group'] ?? '');
                $timeRestricted = (int)($page['starttime'] ?? 0) || (int)($page['endtime'] ?? 0);
                $hidden = (int)($page['hidden'] ?? 0);
                // Stop as soon as a page in the rootline has extendToSubpages set
                break;
            }
        } catch (\Exception) {
            // if the rootline cannot be resolved (404 because of delete placeholder in workspaces for example)
            // we do not want to fail here but rather continue handling the request to trigger the middleware 404 handling
        }
        return $groupRestricted || $timeRestricted || $hidden;
    }

    /**
     * The editor of a token sees a hidden page as with a backend login, unless the token
     * shows all hidden pages anyway
     */
    private function editorSeesHiddenPage(PreviewToken $previewToken, ServerRequestInterface $request): bool
    {
        if ($previewToken->userId === null) {
            return false;
        }
        if ($previewToken->profile->hiddenPages) {
            return false;
        }
        $pageArguments = $request->getAttribute('routing');
        if (!$pageArguments instanceof PageArguments) {
            return false;
        }
        if ($this->checkIfPageIsHidden($pageArguments->getPageId(), $request)) {
            return true;
        }
        return $this->checkIfRootlineRequiresPreview($pageArguments->getPageId());
    }

    /**
     * Checks if the page is hidden in the active workspace + language setup.
     */
    protected function checkIfPageIsHidden(int $pageId, ServerRequestInterface $request): bool
    {
        $site = $request->getAttribute('site', null);
        // always check both the page in the requested language and the page in the default language, as due to the
        // overlay handling, a hidden default page will require setting the preview flag to allow previewing of the
        // translation
        $languageAspectFromRequest = LanguageAspectFactory::createFromSiteLanguage($request->getAttribute('language', $site->getDefaultLanguage()));
        $pageIsHidden = $this->pageRepository->checkIfPageIsHidden($pageId, $languageAspectFromRequest);

        if ($languageAspectFromRequest->getId() > LanguageMarker::DEFAULT_LANGUAGE) {
            $pageIsHidden = $pageIsHidden || $this->pageRepository->checkIfPageIsHidden(
                $pageId,
                LanguageAspectFactory::createFromSiteLanguage($site->getDefaultLanguage())
            );
        }
        return $pageIsHidden;
    }

    /**
     * Simulate dates for preview functionality
     * When previewing a time restricted page from the backend, the parameter ADMCMD_simTime it added containing
     * a timestamp with the time to preview. The global 'SIM_EXEC_TIME' and the 'DateTimeAspect'
     * are used to simulate rendering at that point in time.
     * Ideally the global access is removed in future versions.
     * This functionality needs to be loaded after BackendAuthenticator as it is only relevant for
     * logged in backend users and needs to be done before any page resolving starts.
     */
    protected function simulateDate(ServerRequestInterface $request): bool
    {
        $queryTime = (int)($request->getQueryParams()['ADMCMD_simTime'] ?? 0);
        if ($queryTime === 0) {
            return false;
        }

        $GLOBALS['SIM_EXEC_TIME'] = $queryTime;
        $this->context->setAspect('date', new DateTimeAspect(DateTimeFactory::createFromTimestamp($queryTime)));
        return true;
    }

    /**
     * The groups of a preview token replace the groups of a logged-in frontend user,
     * so the preview shows exactly what members of these groups see.
     *
     * @param list<int> $groups
     */
    private function replaceUserGroups(ServerRequestInterface $request, array $groups): void
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if (!is_array($frontendUser->user)) {
            $frontendUser->user = [$frontendUser->userid_column => PHP_INT_MAX];
        }
        $frontendUser->user[$frontendUser->usergroup_column] = implode(',', $groups);
        // Set this option so the is_online timestamp is not updated in updateOnlineTimestamp()
        $frontendUser->user['is_online'] = $this->context->getPropertyFromAspect('date', 'timestamp');
        $frontendUser->userGroups = [];
        foreach ($groups as $group) {
            $frontendUser->userGroups[$group] = ['uid' => $group, 'title' => '_PREVIEW_'];
        }
        $this->context->setAspect('frontend.user', $frontendUser->createUserAspect());
    }

    /**
     * Simulate user group for preview functionality. When previewing a page with a user group restriction,
     * the parameter ADMCMD_simUser = <groupId> will be added to the preview url. Simulation happens.
     * This functionality needs to be loaded after BackendAuthenticator as it is only relevant for
     * logged in backend users and needs to be done before any page resolving starts.
     */
    protected function simulateUserGroup(ServerRequestInterface $request): bool
    {
        $simulateUserGroup = (int)($request->getQueryParams()['ADMCMD_simUser'] ?? 0);
        if (!$simulateUserGroup) {
            return false;
        }
        $frontendUser = $request->getAttribute('frontend.user');
        $frontendUser->user[$frontendUser->usergroup_column] = (string)$simulateUserGroup;
        $frontendUser->userGroups[$simulateUserGroup] = [
            'uid' => $simulateUserGroup,
            'title' => '_PREVIEW_',
        ];
        // let's fake having a user with that group, too
        $frontendUser->user[$frontendUser->userid_column] = PHP_INT_MAX;
        // Set this option so the is_online timestamp is not updated in updateOnlineTimestamp()
        $frontendUser->user['is_online'] = $this->context->getPropertyFromAspect('date', 'timestamp');
        $this->context->setAspect('frontend.user', $frontendUser->createUserAspect());
        return true;
    }
}
