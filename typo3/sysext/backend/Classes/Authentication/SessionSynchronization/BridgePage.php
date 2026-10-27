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

namespace TYPO3\CMS\Backend\Authentication\SessionSynchronization;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Loads a document before checking cookies omitted on cross-site navigation.
 *
 * @internal
 */
final readonly class BridgePage
{
    public function __construct(private LanguageServiceFactory $languageServiceFactory) {}

    public function render(array $step, string $sourceOrigin, string $flow, array $allowedOrigins = []): ResponseInterface
    {
        $sourceOrigin = $this->requireOrigin($sourceOrigin);
        $allowedOrigins = array_values(array_unique(array_map(
            $this->requireOrigin(...),
            [$sourceOrigin, ...$allowedOrigins]
        )));
        $stepUri = new Uri((string)($step['url'] ?? ''));
        if (!in_array($this->requireOrigin($stepUri->getScheme() . '://' . $stepUri->getAuthority()), $allowedOrigins, true)) {
            throw new \InvalidArgumentException('The bridge endpoint must belong to an allowed origin.', 1793106001);
        }
        $returnUri = new Uri((string)($step['returnUrl'] ?? $sourceOrigin));
        if ($returnUri->getUserInfo() !== ''
            || $returnUri->getFragment() !== ''
            || $this->requireOrigin($returnUri->getScheme() . '://' . $returnUri->getAuthority()) !== $sourceOrigin
        ) {
            throw new \InvalidArgumentException('The bridge return URL must belong to the source origin.', 1793106003);
        }

        $nonce = base64_encode(random_bytes(24));
        $configuration = json_encode([
            'step' => $step,
            'sourceOrigin' => $sourceOrigin,
            'flow' => $flow,
            'allowedOrigins' => $allowedOrigins,
            'returnUrl' => (string)$returnUri,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $script = <<<'JAVASCRIPT'
const configuration = CONFIGURATION;
const allowedOrigins = new Set(configuration.allowedOrigins);
const framed = window.parent !== window;
const notify = (result) => {
    if (framed) {
        window.parent.postMessage({type: 'typo3-session-synchronization', flow: configuration.flow, result}, configuration.sourceOrigin);
    }
};
const trustedUrl = (value) => {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password || !allowedOrigins.has(url.origin)) {
        throw new Error('Invalid synchronization endpoint');
    }
    return url;
};
const complete = (result) => {
    notify(result.result || 'failed');
    if (!framed) {
        const returnUrl = trustedUrl(result.returnUrl || configuration.returnUrl);
        if (returnUrl.origin === configuration.sourceOrigin) {
            window.location.replace(returnUrl.href);
        }
    }
};
const submit = (step) => {
    const url = trustedUrl(step.url);
    const form = document.createElement('form');
    form.method = 'post';
    form.action = url.href;
    for (const [name, value] of Object.entries(step.fields || {})) {
        if (typeof value !== 'string') {
            throw new Error('Invalid synchronization field');
        }
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
    }
    document.body.append(form);
    HTMLFormElement.prototype.submit.call(form);
};
const advance = async () => {
    const url = trustedUrl(configuration.step.url);
    if (url.origin !== window.location.origin) {
        throw new Error('The cookie check must use the current origin');
    }
    const response = await fetch(url.href, {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'error',
        cache: 'no-store',
        signal: AbortSignal.timeout(5000),
        body: new URLSearchParams(configuration.step.fields),
    });
    if (!response.ok) {
        throw new Error('Synchronization failed');
    }
    const result = await response.json();
    if (result.navigationRequired) {
        if (framed) {
            notify('navigation-required');
        } else {
            complete({result: 'failed', returnUrl: configuration.returnUrl});
        }
    } else if (result.next) {
        const nextUrl = trustedUrl(result.next.url);
        if (nextUrl.origin === window.location.origin && result.next.request === true) {
            configuration.step = result.next;
            await advance();
        } else {
            submit(result.next);
        }
    } else {
        complete(result);
    }
};
advance().catch(() => complete({result: 'failed', returnUrl: configuration.returnUrl}));
JAVASCRIPT;
        $script = str_replace('CONFIGURATION', $configuration, $script);
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        $labelReference = 'backend.session_synchronization:';
        $title = htmlspecialchars($languageService->sL($labelReference . 'bridge.completing'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $returnLabel = htmlspecialchars($languageService->sL($labelReference . 'bridge.return'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $language = htmlspecialchars((string)($languageService->getLocale() ?? 'en'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $returnUrl = htmlspecialchars((string)$returnUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="' . $language . '"><head><meta charset="utf-8"><meta name="referrer" content="strict-origin"><title>' . $title . '</title></head>'
            . '<body><p>' . $title . '</p><p><a href="' . $returnUrl . '">' . $returnLabel . '</a></p><script nonce="' . $nonce . '">' . $script . '</script></body></html>';
        $origins = implode(' ', $allowedOrigins);

        return new HtmlResponse($html, 200, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'strict-origin',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-" . $nonce . "'; connect-src 'self'; form-action " . $origins . '; frame-ancestors ' . $sourceOrigin . "; base-uri 'none'; object-src 'none'",
        ]);
    }

    private function requireOrigin(string $origin): string
    {
        $uri = new Uri($origin);
        if ($uri->getScheme() !== 'https'
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== ''
            || $uri->getPath() !== ''
            || $uri->getQuery() !== ''
            || $uri->getFragment() !== ''
            || preg_match('/[\x00-\x20\x7f]/', $origin)
            || $origin !== 'https://' . $uri->getAuthority()
        ) {
            throw new \InvalidArgumentException('A bridge origin must be an absolute HTTPS origin.', 1793106002);
        }
        return $origin;
    }
}
