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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Page\ResourceHashCollection;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\DirectiveHashCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\HashValue;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\SystemResource\Type\PublicResourceInterface;
use TYPO3\CMS\Frontend\Middleware\PreviewTokenAuthenticator;

/**
 * Adds the navigation script of a preview to the page in the frame, under the
 * Content-Security-Policy of the site.
 *
 * @internal
 */
final readonly class PreviewNavigationScript
{
    private const NAVIGATION_SCRIPT = 'EXT:frontend/Resources/Public/JavaScript/preview-navigation.js';

    public function __construct(
        private RequestId $requestId,
        private SystemResourceFactory $resourceFactory,
        private SystemResourcePublisherInterface $resourcePublisher,
        private StreamFactoryInterface $streamFactory,
        private DirectiveHashCollection $directiveHashCollection,
        private ResourceHashCollection $resourceHashCollection,
    ) {}

    /**
     * Lets the handler render the page and adds the script to it when it is the HTML
     * page in the frame.
     */
    public function addToPage(ServerRequestInterface $request, RequestHandlerInterface $handler, PreviewToken $token, int $pageId): ResponseInterface
    {
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
        if (!PreviewTokenAuthenticator::isFrameRequest($request)) {
            return $response;
        }
        if (!str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return $response;
        }
        return $this->withScript($request, $response, $token, $pageId, $script, $nonce, $hash);
    }

    /**
     * The script carries the nonce of the request and, where a policy of the site lists
     * its hash, its integrity, so it runs under nonce-based and hash-based policies. The
     * integrity is left out elsewhere, as it blocks the script if a proxy changes the file.
     */
    private function withScript(
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
            . ' data-preview-expires="' . $token->expires . '"'
            . ' data-preview-page="' . $pageId . '"></script>';
        $content = (string)$response->getBody();
        $position = strripos($content, '</body>');
        $content = $position === false ? $content . $script : substr_replace($content, $script, $position, 0);
        $response = $response->withBody($this->streamFactory->createStream($content));
        return $response->hasHeader('Content-Length') ? $response->withHeader('Content-Length', (string)strlen($content)) : $response;
    }
}
