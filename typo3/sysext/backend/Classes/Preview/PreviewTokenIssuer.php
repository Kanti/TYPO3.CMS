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

use GuzzleHttp\Psr7\UriResolver;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Preview\PreviewGroupFilter;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewScopeResolver;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Signs a preview token for the logged-in editor from the existing rights:
 * web mounts, page permission, workspace and languages. No permission of its own
 * is needed. The site checks these rights again on every request, with the editor
 * as backend user. Frontend user groups come from the access restrictions of the
 * page. Hidden pages are shown as for a backend login: the requested hidden page,
 * unless the token asks for all hidden pages.
 *
 * @internal
 */
final readonly class PreviewTokenIssuer
{
    public const ADDRESS_OUTSIDE_PREVIEW = 1793265780;
    /**
     * Preview parameters an address may already carry: the ADMCMD_ parameters, whose
     * simulation moves into the token, and the token of an earlier preview
     */
    private const PREVIEW_PARAMETERS = ['ADMCMD_prev', 'ADMCMD_simTime', 'ADMCMD_simUser', PreviewTokenService::PARAMETER];

    public function __construct(
        private PreviewTokenService $tokenService,
        private PreviewScopeResolver $scopeResolver,
        private UriBuilder $uriBuilder,
        private ClockInterface $clock,
        private PreviewGroupFilter $groupFilter,
    ) {}

    /**
     * Signs a token for a frontend address as PreviewUriBuilder builds it: the simulated
     * date and user groups of an access-restricted page move from that address into the token.
     *
     * @param int|null $workspace the workspace of the preview, null for the workspace of the editor
     * @throws PreviewAccessDeniedException
     */
    public function issue(
        ServerRequestInterface $request,
        BackendUserAuthentication $user,
        UriInterface $frontendUri,
        int $pageId,
        ?int $workspace = null,
    ): IssuedPreview {
        parse_str($frontendUri->getQuery(), $query);
        $groups = GeneralUtility::intExplode(',', (string)($query['ADMCMD_simUser'] ?? ''), true);
        return $this->sign($request, $user, $frontendUri, $pageId, new PreviewProfile(
            workspace: $workspace ?? $user->workspace,
            hiddenPages: false,
            hiddenContent: false,
            date: max(0, (int)($query['ADMCMD_simTime'] ?? 0)),
            groups: $this->filterGroups($user, $pageId, $groups),
        ));
    }

    /**
     * Signs a token without the editor for an address as a visitor sees it now: live,
     * without hidden records, at the current time and without a frontend login. It
     * lets the live page into a frame next to a preview, also for an editor without
     * access to the live workspace, and grants nothing beyond what a visitor gets.
     * It is valid on the sites the editor may preview, as a token of the editor.
     *
     * @throws PreviewAccessDeniedException
     */
    public function issueForVisitor(
        ServerRequestInterface $request,
        BackendUserAuthentication $user,
        UriInterface $frontendUri,
        int $pageId,
    ): IssuedPreview {
        return $this->sign($request, $user, $frontendUri, $pageId, new PreviewProfile(0, false, false, 0, []), true);
    }

    /**
     * Signs a fresh token for an address of the frame. The simulation stays as signed in the
     * previous token, and a token without the editor stays one; all rights of the editor are
     * checked again, also for its groups.
     *
     * @throws PreviewAccessDeniedException
     */
    public function renew(
        ServerRequestInterface $request,
        BackendUserAuthentication $user,
        string $previousJwt,
        UriInterface $frontendUri,
        int $pageId,
    ): IssuedPreview {
        try {
            $previous = $this->tokenService->readForRenewal($previousJwt, $request->getHeaderLine('User-Agent'));
        } catch (PreviewTokenRejectedException $exception) {
            throw new PreviewAccessDeniedException('Previous preview token is not valid', 1793276924, $exception);
        }
        // Only the editor who opened the preview renews its token, never another editor
        // logged in to the same browser since
        if ($previous->editorId !== (int)$user->user['uid']) {
            throw new PreviewAccessDeniedException('Previous preview token belongs to another editor', 1794491403);
        }
        if ($previous->userId === null) {
            return $this->issueForVisitor($request, $user, $frontendUri, $pageId);
        }
        $profile = $previous->profile;
        return $this->sign($request, $user, $frontendUri, $pageId, new PreviewProfile(
            $profile->workspace,
            $profile->hiddenPages,
            $profile->hiddenContent,
            $profile->date,
            $this->filterGroups($user, $pageId, $profile->groups),
            $profile->loggedIn,
        ));
    }

    /**
     * Settings for the frame controller in the browser, which renews the token: the hosts
     * the frame may navigate to with a fresh token are the hosts of the preview as they are now.
     */
    public function getFrameSettings(IssuedPreview $preview, int $pageId): array
    {
        return [
            'url' => (string)$preview->uri,
            'renewUrl' => (string)$this->uriBuilder->buildUriFromRoute('ajax_preview_token'),
            'page' => $pageId,
            'hosts' => $preview->scope->getHosts(),
            'expiresIn' => max(0, $preview->token->expires - $this->clock->now()->getTimestamp()),
        ];
    }

    /**
     * Settings for a frame that shows an address outside the hosts of the preview as it is,
     * without a preview token. A relative address is resolved against the backend.
     */
    public function getPlainFrameSettings(ServerRequestInterface $request, UriInterface $uri, int $pageId): array
    {
        return [
            'url' => (string)PreviewTokenService::withoutToken(UriResolver::resolve($this->getBackendUri($request), $uri)),
            'renewUrl' => '',
            'page' => $pageId,
            'hosts' => [],
            'expiresIn' => 0,
        ];
    }

    /**
     * @throws PreviewAccessDeniedException
     */
    private function sign(
        ServerRequestInterface $request,
        BackendUserAuthentication $user,
        UriInterface $frontendUri,
        int $pageId,
        PreviewProfile $profile,
        bool $visitor = false,
    ): IssuedPreview {
        if (!$visitor && $user->checkWorkspace($profile->workspace) === false) {
            throw new PreviewAccessDeniedException('Workspace is not accessible', 1793265775);
        }
        if ($pageId > 0 && BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) === false) {
            throw new PreviewAccessDeniedException('Page is not accessible', 1793265778);
        }

        $backendUri = $this->getBackendUri($request);
        // Sites without a host in their base link relative to the backend host
        $frontendUri = UriResolver::resolve(
            $backendUri,
            $frontendUri->withQuery(PreviewTokenService::withoutParameters($frontendUri->getQuery(), self::PREVIEW_PARAMETERS))
        );
        $scope = $this->scopeResolver->resolve($user, $backendUri);
        if ($scope->isEmpty()) {
            throw new PreviewAccessDeniedException('No site is accessible', 1793265779);
        }
        if (!$scope->allowsUri($frontendUri)) {
            throw new PreviewAccessDeniedException('Address is not part of the preview', 1793265780);
        }
        $token = $this->tokenService->sign(
            $profile,
            (string)$backendUri->withPath('')->withQuery('')->withFragment('')->withUserInfo(''),
            $request->getHeaderLine('User-Agent'),
            (int)$user->user['uid'],
            $visitor,
        );
        return new IssuedPreview($token, PreviewTokenService::withToken($frontendUri, $token->jwt), $scope);
    }

    private function getBackendUri(ServerRequestInterface $request): UriInterface
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        return $normalizedParams instanceof NormalizedParams
            ? new Uri($normalizedParams->getSiteUrl())
            : $request->getUri();
    }

    /**
     * Only groups the editor may read and groups named by the access restrictions of the page
     * are simulated; others are left out. "-1" (hide at login) and "-2" (any login, already
     * replaced by a group) need no group.
     *
     * @param list<int> $groups
     * @return list<int>
     */
    private function filterGroups(BackendUserAuthentication $user, int $pageId, array $groups): array
    {
        return $this->groupFilter->filter($user, $groups, $pageId > 0 ? BackendUtility::BEgetRootLine($pageId) : []);
    }
}
