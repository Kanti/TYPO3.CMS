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

use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Domain\DateTimeFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

/** @internal */
final readonly class PreviewAccess implements MiddlewareInterface
{
    public function __construct(
        private Context $context,
        private PreviewTokenService $tokens,
        private RequestId $requestId,
        private SystemResourceFactory $resourceFactory,
        private SystemResourcePublisherInterface $resourcePublisher,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!array_key_exists('_ps', $request->getQueryParams())) {
            return $handler->handle($request);
        }
        try {
            $token = $request->getQueryParams()['_ps'];
            if (!is_string($token) || $token === '') {
                throw new \UnexpectedValueException('Missing preview token', 1793200809);
            }
            $claims = $this->tokens->decode($token);
            $user = $this->tokens->loadPermissionUser($claims);
            $site = $request->getAttribute('site');
            $language = $request->getAttribute('language');
            if (!$site instanceof Site || !$this->tokens->allowsUri($claims, $request->getUri())
                || !isset($claims['scope'][$site->getIdentifier()])
                || ($language !== null && (!isset($claims['scope'][$site->getIdentifier()][$language->getLanguageId()]) || !$user->checkLanguageAccess($language)))
                || ($language !== null && !$language->isEnabled() && !$claims['visibility']['disabledLanguages'])
            ) {
                throw new \UnexpectedValueException('Preview scope denied', 1793200810);
            }
        } catch (\UnexpectedValueException|\InvalidArgumentException|\DomainException) {
            return $this->headers(new HtmlResponse('Preview access denied.', 403));
        }

        $GLOBALS['BE_USER'] = null;
        $this->context->setAspect('backend.user', new UserAspect());
        if ($language !== null) {
            $this->context->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));
        }
        $this->context->setAspect('workspace', new WorkspaceAspect($claims['workspace']));
        $this->context->setAspect('visibility', new VisibilityAspect(
            includeHiddenPages: $claims['visibility']['hiddenPages'],
            includeHiddenContent: $claims['visibility']['hiddenContent'],
            includeScheduledRecords: $claims['visibility']['scheduledRecords'],
        ));
        if ($claims['date'] > 0) {
            $this->context->setAspect('date', new DateTimeAspect(DateTimeFactory::createFromTimestamp($claims['date'])));
            $GLOBALS['SIM_EXEC_TIME'] = $claims['date'];
            $GLOBALS['SIM_ACCESS_TIME'] = $claims['date'] - $claims['date'] % 60;
        }
        $this->context->setAspect('frontend.preview', new PreviewAspect(true));
        $cacheInstruction = $request->getAttribute('frontend.cache.instruction', new CacheInstruction());
        $cacheInstruction->disableCache('EXT:frontend: Signed preview bypasses frontend caches.');
        $request = $request->withAttribute('frontend.preview.token', $claims)
            ->withAttribute('frontend.preview.user', $user)
            ->withAttribute('frontend.cache.instruction', $cacheInstruction);
        // Consume before CSP preparation, even when the page does not use PageRenderer.
        $nonce = $this->requestId->nonce->consumeStatic(self::class);
        $response = $handler->handle($request);
        if (str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html') && $response->getStatusCode() < 300) {
            $resource = $this->resourceFactory->createPublicResource('EXT:core/Resources/Public/JavaScript/preview-navigation.js');
            $script = '<script type="module" src="' . htmlspecialchars((string)$this->resourcePublisher->generateUri($resource, $request))
                . '" nonce="' . htmlspecialchars($nonce) . '" data-typo3-preview-navigation data-parent="' . htmlspecialchars($claims['parent'])
                . '" data-scope="' . htmlspecialchars(json_encode($claims['scope'], JSON_THROW_ON_ERROR)) . '"></script>';
            $content = (string)$response->getBody();
            $position = strripos($content, '</body>');
            $content = $position === false ? $content . $script : substr_replace($content, $script, $position, 0);
            $response = $response->withBody($this->streamFactory->createStream($content))->withoutHeader('ETag');
            if ($response->hasHeader('Content-Length')) {
                $response = $response->withHeader('Content-Length', (string)strlen($content));
            }
        }
        if ($response->hasHeader('Location')) {
            $target = UriResolver::resolve($request->getUri(), new Uri($response->getHeaderLine('Location')));
            if ($this->tokens->allowsUri($claims, $target)) {
                $target = $this->tokens->withToken($target, $token);
            } else {
                parse_str($target->getQuery(), $query);
                unset($query['_ps']);
                $target = $target->withQuery(HttpUtility::buildQueryString($query));
            }
            $response = $response->withHeader('Location', (string)$target);
        }
        // TYPO3's default X-Frame-Options must not contradict the preview's frame-ancestors.
        $response = $response->withoutHeader('X-Frame-Options');
        if (!$response->hasHeader('Content-Security-Policy')) {
            $response = $response->withHeader('Content-Security-Policy', 'frame-ancestors ' . $claims['parent']);
        }
        return $this->headers($response);
    }

    #[AsEventListener]
    public function allowBackendFrame(PolicyMutatedEvent $event): void
    {
        $claims = $event->request?->getAttribute('frontend.preview.token');
        if ($claims !== null) {
            $event->setCurrentPolicy($event->getCurrentPolicy()->set(Directive::FrameAncestors, new UriValue($claims['parent'])));
        }
    }

    private function headers(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
