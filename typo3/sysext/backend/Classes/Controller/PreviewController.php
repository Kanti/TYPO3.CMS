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
use TYPO3\CMS\Backend\Template\PageRendererBackendSetupTrait;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/** @internal */
#[AsController]
final readonly class PreviewController
{
    use PageRendererBackendSetupTrait;

    public function __construct(
        private PreviewTokenService $tokens,
        private BackendViewFactory $viewFactory,
        private PageRenderer $pageRenderer,
        private ExtensionConfiguration $extensionConfiguration,
        private PolicyRegistry $policyRegistry,
    ) {}

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $query = $request->getQueryParams();
            if (!is_string($query['previewToken'] ?? null) || !is_string($query['previewUrl'] ?? null)) {
                return new HtmlResponse('Preview access denied.', 403, ['Cache-Control' => 'private, no-store']);
            }
            $token = $this->tokens->renew($query['previewToken'], $GLOBALS['BE_USER']);
            $claims = $this->tokens->decode($token);
            $uri = new Uri($query['previewUrl']);
            $parent = (string)$request->getUri()->withPath('')->withQuery('')->withFragment('');
            if ($claims['parent'] !== $parent || !$this->tokens->allowsUri($claims, $uri)) {
                return new HtmlResponse('Preview access denied.', 403, ['Cache-Control' => 'private, no-store']);
            }
        } catch (\UnexpectedValueException|\InvalidArgumentException|\DomainException) {
            return new HtmlResponse('Preview access denied.', 403, ['Cache-Control' => 'private, no-store']);
        }
        foreach ($claims['scope'] as $languages) {
            foreach ($languages as $base) {
                $this->policyRegistry->appendMutationCollection(new MutationCollection(
                    new Mutation(MutationMode::Extend, Directive::FrameSrc, new UriValue($base))
                ));
            }
        }
        $view = $this->viewFactory->create($request);
        $liveUri = $this->tokens->withToken($uri, $token);
        parse_str($liveUri->getQuery(), $liveQuery);
        unset($liveQuery['_ps']);
        $liveUri = $liveUri->withQuery(http_build_query($liveQuery));
        $view->assignMultiple(['url' => (string)$this->tokens->withToken($uri, $token), 'liveUrl' => (string)$liveUri]);
        $this->setUpBasicPageRendererForBackend($this->pageRenderer, $this->extensionConfiguration, $request, $GLOBALS['LANG']);
        $this->pageRenderer->setTitle($GLOBALS['LANG']->sL('backend.preview:title'));
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/preview-shell.js');
        $this->pageRenderer->getJavaScriptRenderer()->includeAllImports();
        $this->pageRenderer->addBodyContent('<body>' . $view->render('Preview/Show'));
        return $this->pageRenderer->renderResponse($request)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function renewAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $request->getParsedBody();
            if (!is_string($body['previewToken'] ?? null) || !is_string($body['url'] ?? null)) {
                throw new \UnexpectedValueException('Invalid preview renewal', 1793200812);
            }
            $token = $this->tokens->renew($body['previewToken'], $GLOBALS['BE_USER']);
            $claims = $this->tokens->decode($token);
            $uri = new Uri($body['url']);
            $parent = (string)$request->getUri()->withPath('')->withQuery('')->withFragment('');
            if ($claims['parent'] !== $parent || !$this->tokens->allowsUri($claims, $uri)) {
                throw new \UnexpectedValueException('Invalid preview destination', 1793200813);
            }
            return new JsonResponse([
                'url' => (string)$this->tokens->withToken($uri, $token),
                'expires' => $claims['exp'],
            ], headers: ['Cache-Control' => 'private, no-store']);
        } catch (\UnexpectedValueException|\InvalidArgumentException|\DomainException) {
            return new JsonResponse(['error' => 'Preview access denied.'], 403, ['Cache-Control' => 'private, no-store']);
        }
    }
}
