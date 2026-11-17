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

namespace TYPO3\CMS\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Preview\AddressOutsidePreviewException;
use TYPO3\CMS\Backend\Preview\PreviewAccessDeniedException;
use TYPO3\CMS\Backend\Preview\PreviewTokenIssuer;
use TYPO3\CMS\Backend\Template\PageRendererBackendSetupTrait;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;

/**
 * The preview page of the backend: the site in a frame, loaded with a
 * short-lived preview token. Also signs fresh tokens for the frame.
 *
 * @internal This is a specific Backend Controller implementation and is not considered part of the Public TYPO3 API.
 */
#[AsController]
final readonly class PreviewPageController
{
    use PageRendererBackendSetupTrait;

    public function __construct(
        private PreviewTokenIssuer $issuer,
        private BackendViewFactory $viewFactory,
        private PageRenderer $pageRenderer,
        private ExtensionConfiguration $extensionConfiguration,
        private PolicyRegistry $policyRegistry,
    ) {}

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $pageId = (int)($query['page'] ?? 0);
        try {
            $frontendUri = new Uri(is_string($query['url'] ?? null) ? $query['url'] : '');
        } catch (\InvalidArgumentException) {
            // A malformed address, as a hand-edited or truncated link may carry
            return $this->createDeniedResponse();
        }
        try {
            $preview = $this->issuer->issue($request, $this->getBackendUser(), $frontendUri, $pageId);
        } catch (AddressOutsidePreviewException) {
            // A frontend address an event listener changed to a host outside the preview, such as a
            // decoupled frontend, opens as it is, without a token
            return new RedirectResponse(PreviewTokenService::withoutToken($frontendUri), 303);
        } catch (PreviewAccessDeniedException) {
            return $this->createDeniedResponse();
        }
        foreach ($preview->scope->getFrameSources() as $source) {
            $this->policyRegistry->appendMutationCollection(new MutationCollection(
                new Mutation(MutationMode::Extend, Directive::FrameSrc, $source)
            ));
        }
        $view = $this->viewFactory->create($request);
        $view->assign('settings', json_encode($this->issuer->getFrameSettings($preview, $pageId), JSON_THROW_ON_ERROR));
        $languageService = $this->getLanguageService();
        $this->setUpBasicPageRendererForBackend($this->pageRenderer, $this->extensionConfiguration, $request, $languageService);
        $this->pageRenderer->setTitle($languageService->sL('backend.preview:title'));
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/preview/preview-page.js');
        $this->pageRenderer->setBodyContent('<body>' . $view->render('PreviewPage/Show'));
        return new HtmlResponse($this->pageRenderer->render($request))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Signs a fresh token for an address of the frame. Rights are checked again on every call,
     * the simulation is taken from the previous token, and the hosts of the preview are
     * resolved again.
     */
    public function tokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $pageId = (int)($body['page'] ?? 0);
        try {
            $frontendUri = new Uri(is_string($body['url'] ?? null) ? $body['url'] : '');
        } catch (\InvalidArgumentException) {
            // An address the page around the frame could not have taken from the frame
            return $this->createDeniedJsonResponse();
        }
        try {
            $preview = $this->issuer->renew(
                $request,
                $this->getBackendUser(),
                is_string($body['previewToken'] ?? null) ? $body['previewToken'] : '',
                $frontendUri,
                $pageId,
            );
        } catch (PreviewAccessDeniedException) {
            return $this->createDeniedJsonResponse();
        }
        $settings = $this->issuer->getFrameSettings($preview, $pageId);
        return new JsonResponse(
            ['url' => $settings['url'], 'expiresIn' => $settings['expiresIn'], 'hosts' => $settings['hosts'], 'httpsHosts' => $settings['httpsHosts']],
            200,
            ['Cache-Control' => 'private, no-store']
        );
    }

    private function createDeniedJsonResponse(): ResponseInterface
    {
        return new JsonResponse(['error' => 'denied'], 403, ['Cache-Control' => 'private, no-store']);
    }

    private function createDeniedResponse(): ResponseInterface
    {
        return new HtmlResponse(
            htmlspecialchars($this->getLanguageService()->sL('backend.preview:error.denied')),
            403,
            ['Cache-Control' => 'private, no-store']
        );
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
