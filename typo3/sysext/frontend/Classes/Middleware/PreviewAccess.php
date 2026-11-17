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
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Domain\DateTimeFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ImmediateResponseException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Preview\PreviewHosts;
use TYPO3\CMS\Core\Preview\PreviewHostsResolver;
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Authentication\PreviewBackendUserInitializer;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

/**
 * Turns a preview token in the address (`__t3ct`) into a preview context for the
 * request. An invalid token never falls back to the live page. The changelog entry
 * Important-110664-PreviewsOfHiddenPagesWorkOnEveryDomain lists the checks and what
 * the token replaces.
 *
 * @internal
 */
final readonly class PreviewAccess implements MiddlewareInterface
{
    public const ATTRIBUTE = 'frontend.preview.token';
    /**
     * The sites and hosts of the preview, resolved once per request, see PreviewHosts
     */
    public const HOSTS_ATTRIBUTE = 'frontend.preview.hosts';

    public function __construct(
        private Context $context,
        private PreviewTokenService $tokenService,
        private LanguageServiceFactory $languageServiceFactory,
        private PreviewBackendUserInitializer $backendUserInitializer,
        private PreviewHostsResolver $hostsResolver,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!array_key_exists(PreviewTokenService::PARAMETER, $request->getQueryParams())) {
            return $handler->handle($request);
        }
        $kind = PreviewRequestKind::fromRequest($request);
        if ($kind === PreviewRequestKind::Window) {
            return $this->redirectWithoutToken($request);
        }
        $token = null;
        try {
            $token = $this->verify($request, $kind);
            $scope = $this->authorize($request, $token);
            return $this->respond($this->applyProfile($request, $token, $scope), $handler, $token, $scope);
        } catch (PreviewTokenRejectedException $exception) {
            // Every refusal of the preview ends here, also those of PreviewSimulator further down the
            // stack. An invalid preview tells the backend nothing: its token could not be verified, or
            // the page is on a host outside the preview.
            $reportedToken = $exception->rejection === PreviewRejection::Invalid ? null : ($exception->token ?? $token);
            return $this->createErrorResponse($request, $exception->rejection, $reportedToken);
        }
    }

    /**
     * The token is one this installation signed for this browser, and it is sent by the
     * page in the frame or by a request of that page.
     *
     * @throws PreviewTokenRejectedException
     */
    private function verify(ServerRequestInterface $request, PreviewRequestKind $kind): PreviewToken
    {
        $jwt = $request->getQueryParams()[PreviewTokenService::PARAMETER];
        $token = $this->tokenService->verify(is_string($jwt) ? $jwt : '', $request->getHeaderLine('User-Agent'));
        if (!$this->isFramed($request, $kind)) {
            throw new PreviewTokenRejectedException(PreviewRejection::NotInFrame, 'Preview is only available in a frame', 1793265772, token: $token);
        }
        return $token;
    }

    /**
     * Makes the editor of the token the backend user of the request, or leaves the request
     * without one for a visitor token, and checks that the request is part of the preview.
     *
     * @throws PreviewTokenRejectedException
     */
    private function authorize(ServerRequestInterface $request, PreviewToken $token): PreviewHosts
    {
        if (!$token->isVisitorWithoutBackendUser()) {
            $this->assertBackendAccessible($request, $token);
            $user = $this->backendUserInitializer->initialize($token);
            $scope = $this->hostsResolver->resolve($user, new Uri($token->backendOrigin));
        } else {
            $user = null;
            $scope = $this->hostsResolver->resolve($this->backendUserInitializer->createEditorOfVisitorToken($token), new Uri($token->backendOrigin));
            $GLOBALS['BE_USER'] = null;
            $this->context->setAspect('backend.user', new UserAspect());
        }
        if (!$scope->allowsHost(PreviewHosts::hostOf($request->getUri()))) {
            // The page on a host outside the preview does not talk to the backend that signed the token, see process()
            throw new PreviewTokenRejectedException(PreviewRejection::Invalid, 'Host is not part of the preview', 1794909953);
        }
        $this->assertSiteInScope($request, $scope);
        if ($user !== null) {
            $this->assertLanguageAccessible($request, $token, $user);
        }
        return $scope;
    }

    /**
     * Sets the simulation of the token, keeps the page out of the page cache and hands token
     * and scope to the rest of the stack.
     */
    private function applyProfile(ServerRequestInterface $request, PreviewToken $token, PreviewHosts $scope): ServerRequestInterface
    {
        $this->context->setAspect('workspace', new WorkspaceAspect($token->profile->workspace));
        $this->context->setAspect('visibility', new VisibilityAspect(
            includeHiddenPages: $token->profile->hiddenPages,
            includeHiddenContent: $token->profile->hiddenContent,
        ));
        if ($token->profile->date > 0) {
            $GLOBALS['SIM_EXEC_TIME'] = $token->profile->date;
            $GLOBALS['SIM_ACCESS_TIME'] = $token->profile->date - ($token->profile->date % 60);
            $this->context->setAspect('date', new DateTimeAspect(DateTimeFactory::createFromTimestamp($token->profile->date)));
        }
        // A token previews something only where its simulation asks for more than a visitor
        // sees. A requested hidden page is one more case, see PreviewSimulator.
        $this->context->setAspect('frontend.preview', new PreviewAspect(!$token->profile->showsVisitorView()));
        $cacheInstruction = $request->getAttribute('frontend.cache.instruction', new CacheInstruction());
        $cacheInstruction->disableCache('EXT:frontend: Preview token bypasses the page cache.');
        return $request
            ->withAttribute(self::ATTRIBUTE, $token)
            ->withAttribute(self::HOSTS_ATTRIBUTE, $scope)
            ->withAttribute('frontend.cache.instruction', $cacheInstruction);
    }

    /**
     * Lets the rest of the stack answer and makes that answer one of the preview: never
     * cached, never indexed, framed only by the backend, and redirects keep the token.
     * A refusal of PreviewSimulator leaves through process().
     *
     * @throws PreviewTokenRejectedException
     */
    private function respond(ServerRequestInterface $request, RequestHandlerInterface $handler, PreviewToken $token, PreviewHosts $scope): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (ImmediateResponseException $exception) {
            // An immediate answer, as extensions send one, is still an answer of the preview
            $response = $exception->getResponse();
        }
        if ($response->hasHeader('Location')) {
            $response = $this->withTokenInLocation($request, $response, $token, $scope);
        }
        return $this->withPreviewHeaders($response, $token);
    }

    /**
     * A redirect to a host of the preview carries the token on. Any other target is left
     * as it is, as signed addresses of login or payment services must keep their bytes.
     * Only a token it would carry to another host is removed.
     */
    private function withTokenInLocation(ServerRequestInterface $request, ResponseInterface $response, PreviewToken $token, PreviewHosts $scope): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');
        if ($scope->allowsUrl($location, $request->getUri())) {
            return $response->withHeader('Location', PreviewTokenService::addToUrl($location, $token->jwt));
        }
        $withoutToken = PreviewTokenService::removeFromUrl($location);
        return $withoutToken === $location ? $response : $response->withHeader('Location', $withoutToken);
    }

    /**
     * Browsers check every ancestor of a frame: a page of the site may frame another page
     * of its own site, which then has the site and the backend as ancestors.
     */
    #[AsEventListener('typo3-frontend/preview-access/frame-ancestors')]
    public function allowBackendToFrame(PolicyMutatedEvent $event): void
    {
        $token = $event->request?->getAttribute(self::ATTRIBUTE);
        if ($token instanceof PreviewToken) {
            $event->setCurrentPolicy(
                $event->getCurrentPolicy()->set(Directive::FrameAncestors, SourceKeyword::self, new UriValue($token->backendOrigin))
            );
        }
    }

    /**
     * Whether the request loads the page into a frame, as browsers tell it to HTTPS sites.
     * Without the header, as on a plain HTTP site, it is taken as one.
     */
    public static function isFrameRequest(ServerRequestInterface $request): bool
    {
        return in_array(PreviewRequestKind::fromRequest($request), [PreviewRequestKind::Frame, PreviewRequestKind::Unknown], true);
    }

    /**
     * Opens the address without the token, before the token is even read: the answer
     * tells nothing about the token, and the token leaves the address bar.
     */
    private function redirectWithoutToken(ServerRequestInterface $request): ResponseInterface
    {
        return new RedirectResponse(
            PreviewTokenService::removeFromUrl($this->getAddressAsSent($request)),
            303,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex']
        );
    }

    /**
     * The address as the browser sent it, so that every byte besides the token stays: the
     * address of the request is an URI object, which encodes brackets, pipes or a stray
     * percent sign anew. A request the web server did not hand over for this address, such
     * as a sub-request, keeps the address of the request.
     */
    private function getAddressAsSent(ServerRequestInterface $request): string
    {
        $uri = (string)$request->getUri();
        if (empty($request->getServerParams()['REQUEST_URI'])) {
            return $uri;
        }
        $normalizedParams = $request->getAttribute('normalizedParams') ?? NormalizedParams::createFromRequest($request);
        $address = $normalizedParams->getRequestHost() . $normalizedParams->getRequestUri();
        try {
            return (string)new Uri($address) === $uri ? $address : $uri;
        } catch (\InvalidArgumentException) {
            return $uri;
        }
    }

    /**
     * A token is accepted for the page in the frame and for the requests that page sends
     * itself. Without Fetch Metadata, a token is accepted only where the installation and
     * the site allow plain HTTP.
     */
    private function isFramed(ServerRequestInterface $request, PreviewRequestKind $kind): bool
    {
        if ($kind === PreviewRequestKind::Unknown) {
            if ($GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL'] ?? false) {
                return false;
            }
            $language = $request->getAttribute('language');
            $scheme = $language instanceof SiteLanguage ? $language->getBase()->getScheme() : '';
            return in_array($scheme, ['http', ''], true);
        }
        return $kind === PreviewRequestKind::Frame || $kind === PreviewRequestKind::OfThePage;
    }

    /**
     * The site of the request is one the editor of the token may preview now.
     *
     * @throws PreviewTokenRejectedException
     */
    private function assertSiteInScope(ServerRequestInterface $request, PreviewHosts $scope): void
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page is not part of a site', 1794411126);
        }
        if (!$scope->includesSite($site->getIdentifier())) {
            throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Page is not part of the preview', 1793265773);
        }
    }

    /**
     * The checks the backend makes before it accepts a backend user, as for a
     * backend user logged in to the frontend.
     *
     * @throws PreviewTokenRejectedException
     */
    private function assertBackendAccessible(ServerRequestInterface $request, PreviewToken $token): void
    {
        $normalizedParams = $request->getAttribute('normalizedParams') ?? NormalizedParams::createFromRequest($request);
        $ipMask = trim((string)($GLOBALS['TYPO3_CONF_VARS']['BE']['IPmaskList'] ?? ''));
        if ($ipMask !== '' && !GeneralUtility::cmpIP($normalizedParams->getRemoteAddress(), $ipMask)) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Backend is not accessible from this address', 1794224124, token: $token);
        }
        if (($GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL'] ?? false) && !$normalizedParams->isHttps()) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Backend is only accessible via HTTPS', 1794411127, token: $token);
        }
    }

    /**
     * @throws PreviewTokenRejectedException
     */
    private function assertLanguageAccessible(ServerRequestInterface $request, PreviewToken $token, BackendUserAuthentication $user): void
    {
        $language = $request->getAttribute('language');
        if (!$language instanceof SiteLanguage) {
            return;
        }
        if (!$user->checkLanguageAccess($language)) {
            throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Language is not part of the preview', 1794224125, token: $token);
        }
    }

    private function createErrorResponse(ServerRequestInterface $request, PreviewRejection $rejection, ?PreviewToken $token): ResponseInterface
    {
        $language = $request->getAttribute('language');
        $languageService = $language instanceof SiteLanguage
            ? $this->languageServiceFactory->createFromSiteLanguage($language)
            : $this->languageServiceFactory->create('en');
        $message = htmlspecialchars($rejection === PreviewRejection::NotInFrame
            // Only scripts and proxies that drop the Fetch Metadata headers of the browser meet it
            ? 'This preview is only available in the backend.'
            : $languageService->sL('frontend.preview:error.' . $rejection->value));
        $script = '';
        if ($token !== null) {
            $data = json_encode([
                'type' => 'typo3:preview:expired',
                'url' => (string)PreviewTokenService::withoutToken($request->getUri()),
                'reason' => $rejection->value,
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $script = '<script>window.parent !== window && window.parent.postMessage(' . $data . ', '
                . json_encode($token->backendOrigin, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ');</script>';
        }
        $html = '<!DOCTYPE html><html lang="' . htmlspecialchars($languageService->getLocale()?->getLanguageCode() ?? 'en') . '">'
            . '<head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . $message . '</title></head>'
            . '<body><p data-preview-rejection="' . $rejection->value . '">' . $message . '</p>' . $script . '</body></html>';
        return $this->withPreviewHeaders(new HtmlResponse($html, 403), $token);
    }

    private function withPreviewHeaders(ResponseInterface $response, ?PreviewToken $token): ResponseInterface
    {
        $response = $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withoutHeader('Expires')
            ->withoutHeader('ETag')
            ->withoutHeader('Last-Modified')
            ->withoutHeader('Pragma')
            ->withoutHeader('X-Frame-Options');
        if ($token === null) {
            return $response;
        }
        foreach ($response->getHeader('Content-Security-Policy') as $policy) {
            if (preg_match('/(^|;)\s*frame-ancestors(\s|;|$)/i', $policy) === 1) {
                return $response;
            }
        }
        // A policy of the site without frame-ancestors gets the site and the backend as a
        // second policy, browsers enforce both
        return $response->withAddedHeader('Content-Security-Policy', "frame-ancestors 'self' " . $token->backendOrigin);
    }
}
