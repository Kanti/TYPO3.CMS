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
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Page\ResourceHashCollection;
use TYPO3\CMS\Core\Preview\PreviewGroupFilter;
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewScope;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\DirectiveHashCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\HashValue;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\SystemResource\Type\PublicResourceInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Refuses a preview of a page the editor of the token may not show, as soon as
 * the page is resolved, decides which of the simulated frontend user groups the
 * editor may simulate on this page, and adds the navigation script to the
 * rendered page. PreviewAccess turns the refusal into its error page.
 *
 * @internal
 */
final readonly class PreviewAccessScope implements MiddlewareInterface
{
    public const GROUPS_ATTRIBUTE = 'frontend.preview.groups';
    private const NAVIGATION_SCRIPT = 'EXT:frontend/Resources/Public/JavaScript/preview-navigation.js';

    public function __construct(
        private Context $context,
        private RequestId $requestId,
        private SystemResourceFactory $resourceFactory,
        private SystemResourcePublisherInterface $resourcePublisher,
        private StreamFactoryInterface $streamFactory,
        private PreviewGroupFilter $groupFilter,
        private DirectiveHashCollection $directiveHashCollection,
        private ResourceHashCollection $resourceHashCollection,
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
        if ($token->userId !== null) {
            $user = $GLOBALS['BE_USER'] ?? null;
            if (!$user instanceof BackendUserAuthentication) {
                throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Backend user of the preview is missing', 1794411128, null, $token);
            }
            $page = reset($rootline);
            if (!is_array($page)) {
                throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page of the preview does not exist', 1794411129, null, $token);
            }
            if (!$user->doesUserHaveAccess($page, Permission::PAGE_SHOW)) {
                throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page is not part of the preview', 1793265774, null, $token);
            }
            $groups = $this->groupFilter->filter($user, $token->profile->groups, $rootline);
        }
        $request = $request->withAttribute(self::GROUPS_ATTRIBUTE, $groups);
        // Nonce and hash are registered before the CSP headers are prepared further down the stack
        $nonce = $this->requestId->nonce->consumeStatic(self::class);
        $script = $this->resourceFactory->createPublicResource(self::NAVIGATION_SCRIPT);
        $hash = $this->resourceHashCollection->fetchResourceHash($script);
        if ($hash !== null) {
            $this->directiveHashCollection->addGenericHashValue(Directive::ScriptSrcElem, $hash);
        }
        $response = $handler->handle($request);
        if ($response->getStatusCode() >= 300) {
            return $response;
        }
        // Only the page in the frame gets the script, not what the page fetches itself
        if (!PreviewAccess::isFrameRequest($request)) {
            return $response;
        }
        if (!str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return $response;
        }
        return $this->withNavigationScript($request, $response, $token, $pageArguments->getPageId(), $script, $nonce, $hash);
    }

    /**
     * The script carries the nonce of the request and, where a policy of the site lists
     * its hash, its integrity, so it runs under nonce-based and hash-based policies. The
     * integrity is left out elsewhere, as it blocks the script if a proxy changes the file.
     */
    private function withNavigationScript(
        ServerRequestInterface $request,
        ResponseInterface $response,
        PreviewToken $token,
        int $pageId,
        PublicResourceInterface $resource,
        string $nonce,
        ?HashValue $hash,
    ): ResponseInterface {
        $policies = $response->getHeaderLine('Content-Security-Policy') . ' ' . $response->getHeaderLine('Content-Security-Policy-Report-Only');
        $integrity = $hash !== null && str_contains($policies, (string)$hash) ? ' integrity="' . htmlspecialchars($hash->export()) . '"' : '';
        $script = '<script type="module" src="' . htmlspecialchars((string)$this->resourcePublisher->generateUri($resource, $request)) . '"'
            . ' nonce="' . htmlspecialchars($nonce) . '"' . $integrity
            . ' data-preview-backend="' . htmlspecialchars($token->backendOrigin) . '"'
            . ' data-preview-hosts="' . htmlspecialchars(json_encode($this->getHosts($request), JSON_THROW_ON_ERROR)) . '"'
            . ' data-preview-expires="' . $token->expires . '"'
            . ' data-preview-page="' . $pageId . '"></script>';
        $content = (string)$response->getBody();
        $position = strripos($content, '</body>');
        $content = $position === false ? $content . $script : substr_replace($content, $script, $position, 0);
        $response = $response->withBody($this->streamFactory->createStream($content));
        return $response->hasHeader('Content-Length') ? $response->withHeader('Content-Length', (string)strlen($content)) : $response;
    }

    /**
     * @return list<string>
     */
    private function getHosts(ServerRequestInterface $request): array
    {
        $scope = $request->getAttribute(PreviewAccess::SCOPE_ATTRIBUTE);
        return $scope instanceof PreviewScope ? $scope->getHosts() : [];
    }
}
