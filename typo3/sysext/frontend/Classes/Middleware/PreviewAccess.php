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
use TYPO3\CMS\Core\Preview\PreviewRejection;
use TYPO3\CMS\Core\Preview\PreviewScope;
use TYPO3\CMS\Core\Preview\PreviewScopeResolver;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Preview\PreviewTokenRejectedException;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Authentication\PreviewBackendUserInitializer;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

/**
 * Turns a preview token in the address (`__t3ct`) into a preview context. The editor
 * the token was signed for becomes a stateless backend user of the request, built
 * again on every request; a token without an editor shows the pages as a visitor
 * sees them. The token replaces any backend login, workspace preview keyword and
 * simulation parameter. An invalid token never falls back to the live page.
 *
 * @internal
 */
final readonly class PreviewAccess implements MiddlewareInterface
{
    public const ATTRIBUTE = 'frontend.preview.token';
    /**
     * The sites and hosts of the preview, resolved once per request, see PreviewScope
     */
    public const SCOPE_ATTRIBUTE = 'frontend.preview.scope';

    public function __construct(
        private Context $context,
        private PreviewTokenService $tokenService,
        private LanguageServiceFactory $languageServiceFactory,
        private PreviewBackendUserInitializer $backendUserInitializer,
        private PreviewScopeResolver $scopeResolver,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!array_key_exists(PreviewTokenService::PARAMETER, $request->getQueryParams())) {
            return $handler->handle($request);
        }
        if ($this->isTopLevelNavigation($request)) {
            return $this->redirectWithoutToken($request);
        }
        $jwt = $request->getQueryParams()[PreviewTokenService::PARAMETER];
        $token = null;
        try {
            $token = $this->tokenService->verify(is_string($jwt) ? $jwt : '', $request->getHeaderLine('User-Agent'));
            if (!$this->isFramed($request)) {
                throw new PreviewTokenRejectedException(PreviewRejection::NotInFrame, 'Preview is only available in a frame', 1793265772);
            }
            if ($token->userId !== null) {
                $this->assertBackendAccessible($request, $token);
                $user = $this->backendUserInitializer->initialize($token);
                $scope = $this->scopeResolver->resolve($user, new Uri($token->backendOrigin));
            } else {
                $user = null;
                $scope = $this->scopeResolver->resolve($this->backendUserInitializer->createEditorOfVisitorToken($token), new Uri($token->backendOrigin));
                $GLOBALS['BE_USER'] = null;
                $this->context->setAspect('backend.user', new UserAspect());
            }
            if (!$scope->allowsHost(PreviewScope::hostOf($request->getUri()))) {
                // The page on a host outside the preview does not talk to the backend that signed the token
                return $this->createErrorResponse($request, PreviewRejection::Invalid, null);
            }
            $this->assertSiteInScope($request, $scope);
            if ($user !== null) {
                $this->assertLanguageAccessible($request, $token, $user);
            }
        } catch (PreviewTokenRejectedException $exception) {
            return $this->createErrorResponse($request, $exception->rejection, $exception->token ?? $token);
        }

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
        // A token previews something only where its simulation asks for more than a visitor sees;
        // a requested hidden page is one more case, see PreviewSimulator
        $this->context->setAspect('frontend.preview', new PreviewAspect(!$token->profile->isVisitorView()));
        $cacheInstruction = $request->getAttribute('frontend.cache.instruction', new CacheInstruction());
        $cacheInstruction->disableCache('EXT:frontend: Preview token bypasses the page cache.');
        $request = $request
            ->withAttribute(self::ATTRIBUTE, $token)
            ->withAttribute(self::SCOPE_ATTRIBUTE, $scope)
            ->withAttribute('frontend.cache.instruction', $cacheInstruction);

        try {
            $response = $handler->handle($request);
        } catch (PreviewTokenRejectedException $exception) {
            // PreviewAccessScope refuses the page and its groups further down the stack
            return $this->createErrorResponse($request, $exception->rejection, $token);
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
     * as it is, as signed addresses of login or payment services must keep their bytes;
     * only a token it would carry to another host is removed.
     */
    private function withTokenInLocation(ServerRequestInterface $request, ResponseInterface $response, PreviewToken $token, PreviewScope $scope): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');
        if ($scope->allowsUrl($location, $request->getUri())) {
            return $response->withHeader('Location', PreviewTokenService::addToUrl($location, $token->jwt));
        }
        $withoutToken = PreviewTokenService::removeFromUrl($location);
        return $withoutToken === $location ? $response : $response->withHeader('Location', $withoutToken);
    }

    #[AsEventListener('typo3-frontend/preview-access/frame-ancestors')]
    public function allowBackendToFrame(PolicyMutatedEvent $event): void
    {
        $token = $event->request?->getAttribute(self::ATTRIBUTE);
        if ($token instanceof PreviewToken) {
            $event->setCurrentPolicy(
                $event->getCurrentPolicy()->set(Directive::FrameAncestors, new UriValue($token->backendOrigin))
            );
        }
    }

    /**
     * Whether the request loads the page into a frame, as browsers tell it to HTTPS sites;
     * without the header, as on a plain HTTP site, it is taken as one.
     */
    public static function isFrameRequest(ServerRequestInterface $request): bool
    {
        if (!$request->hasHeader('Sec-Fetch-Dest')) {
            return true;
        }
        return strtolower($request->getHeaderLine('Sec-Fetch-Dest')) === 'iframe';
    }

    /**
     * A navigation of a whole window or tab, such as a link opened in a new tab or a
     * target _blank or _top, as browsers tell it to HTTPS sites.
     */
    private function isTopLevelNavigation(ServerRequestInterface $request): bool
    {
        $destination = strtolower($request->getHeaderLine('Sec-Fetch-Dest'));
        if ($destination === 'document') {
            return true;
        }
        if (strtolower($request->getHeaderLine('Sec-Fetch-Mode')) !== 'navigate') {
            return false;
        }
        return !in_array($destination, ['', 'iframe', 'frame'], true);
    }

    /**
     * Opens the address without the token, before the token is even read: the answer
     * tells nothing about the token, and the token leaves the address bar.
     */
    private function redirectWithoutToken(ServerRequestInterface $request): ResponseInterface
    {
        return new RedirectResponse(
            (string)PreviewTokenService::withoutToken($request->getUri()),
            303,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex']
        );
    }

    /**
     * A token is accepted for the page in the frame and for the requests that page sends
     * itself: fetch, XHR and elements of the same origin. Only a document of the site sends
     * a same-origin request, and in a preview that is the page in the frame. Browsers send
     * these headers only to HTTPS and localhost. Without them, a token is accepted only
     * where the installation and the site allow plain HTTP.
     */
    private function isFramed(ServerRequestInterface $request): bool
    {
        if (!$request->hasHeader('Sec-Fetch-Dest')) {
            if ($GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL'] ?? false) {
                return false;
            }
            $language = $request->getAttribute('language');
            $scheme = $language instanceof SiteLanguage ? $language->getBase()->getScheme() : '';
            return in_array($scheme, ['http', ''], true);
        }
        if (self::isFrameRequest($request)) {
            return true;
        }
        if (strtolower($request->getHeaderLine('Sec-Fetch-Site')) !== 'same-origin') {
            return false;
        }
        return strtolower($request->getHeaderLine('Sec-Fetch-Mode')) !== 'navigate';
    }

    /**
     * The site of the request is one the editor of the token may preview now.
     *
     * @throws PreviewTokenRejectedException
     */
    private function assertSiteInScope(ServerRequestInterface $request, PreviewScope $scope): void
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
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Backend is not accessible from this address', 1794224124, null, $token);
        }
        if (($GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL'] ?? false) && !$normalizedParams->isHttps()) {
            throw new PreviewTokenRejectedException(PreviewRejection::UserNotAvailable, 'Backend is only accessible via HTTPS', 1794411127, null, $token);
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
            throw new PreviewTokenRejectedException(PreviewRejection::OutOfScope, 'Language is not part of the preview', 1794224125, null, $token);
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
        // A policy of the site without frame-ancestors gets the backend as a second policy,
        // browsers enforce both
        return $response->withAddedHeader('Content-Security-Policy', 'frame-ancestors ' . $token->backendOrigin);
    }
}
