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

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\SetCookieBehavior;
use TYPO3\CMS\Core\Http\SetCookieService;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Core\Session\UserSessionManager;

/**
 * Handles only the dedicated bridge, without running backend authentication on
 * cross-site arrivals where an existing Strict cookie can be absent.
 *
 * @internal
 */
final readonly class TargetHandler
{
    public const BRIDGE_PATH = 'session/synchronize/bridge';

    public function __construct(
        private OriginResolver $originResolver,
        private TransferStore $transferStore,
        private BridgePage $bridgePage,
        private SessionManager $sessionManager,
        private ClockInterface $clock,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->json(['result' => 'failed'], 405)->withHeader('Allow', 'POST');
        }
        $body = $request->getParsedBody();
        $stage = is_array($body) && is_string($body['stage'] ?? null) ? $body['stage'] : '';
        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : '';
        $origin = $this->originResolver->canonicalOrigin($request->getAttribute('normalizedParams')?->getRequestHost() ?? '');
        if ($origin === null) {
            return $this->json(['result' => 'failed'], 403);
        }
        $purpose = match ($stage) {
            'bootstrap' => 'bootstrap',
            'inspect' => 'inspect',
            'source' => 'confirm',
            'apply', 'commit' => 'apply',
            'verify' => 'verify',
            'receipt' => 'receipt',
            default => '',
        };
        $claims = $this->transferStore->inspect($code, $purpose, $origin);
        if ($claims === null || !$this->validClaims($request, $claims, $origin, $stage)) {
            return $this->json(['result' => 'failed'], 403);
        }

        return match ($stage) {
            'bootstrap' => $this->bootstrap($request, $code, $claims),
            'inspect' => $this->inspectTarget($request, $code, $claims),
            'source' => $this->page($claims, $claims['sourceBase'] . 'session/synchronize/confirm', ['code' => $code]),
            'apply' => $this->page($claims, $claims['targetBase'] . self::BRIDGE_PATH, ['stage' => 'commit', 'code' => $code]),
            'commit' => $this->commit($request, $code, $claims),
            'verify' => $this->verify($request, $code, $claims),
            'receipt' => $this->page($claims, $claims['sourceBase'] . 'session/synchronize/receipt', ['code' => $code]),
            default => $this->json(['result' => 'failed'], 403),
        };
    }

    private function bootstrap(ServerRequestInterface $request, string $code, array $claims): ResponseInterface
    {
        if ($this->transferStore->consume($code, 'bootstrap', $claims['target']) === null) {
            return $this->json(['result' => 'failed'], 403);
        }
        $secret = bin2hex(random_bytes(32));
        $claims['targetCookie'] = '__Secure-typo3-sync-target-' . $claims['flow'];
        $claims['targetHash'] = hash('sha256', $secret);
        $next = $this->transferStore->issue('inspect', $claims['target'], $claims);
        $response = $this->page($claims, $claims['targetBase'] . self::BRIDGE_PATH, ['stage' => 'inspect', 'code' => $next]);
        return $response->withAddedHeader('Set-Cookie', (string)$this->nonceCookie($claims, $secret));
    }

    private function inspectTarget(ServerRequestInterface $request, string $code, array $claims): ResponseInterface
    {
        // A cross-site iframe cannot retain this Strict nonce. It must not infer
        // that an absent backend cookie means there is no existing login.
        if (!$this->hasTargetNonce($request, $claims)) {
            $this->transferStore->remove($code);
            return $this->json(['navigationRequired' => true]);
        }
        if ($this->liveSource($claims) === null) {
            return $this->json(['result' => 'failed', 'returnUrl' => $claims['returnUrl']], 403);
        }
        if ($this->transferStore->consume($code, 'inspect', $claims['target']) === null) {
            return $this->json(['result' => 'failed'], 403);
        }
        if ($this->hasOtherUser($request, $claims)) {
            return $this->receipt($claims, 'skipped');
        }
        $confirm = $this->transferStore->issue('confirm', $claims['source'], $claims);
        return $this->next($claims['sourceBase'] . self::BRIDGE_PATH, ['stage' => 'source', 'code' => $confirm]);
    }

    private function commit(ServerRequestInterface $request, string $code, array $claims): ResponseInterface
    {
        if (!$this->hasTargetNonce($request, $claims) || ($session = $this->liveSource($claims)) === null) {
            return $this->json(['result' => 'failed', 'returnUrl' => $claims['returnUrl']], 403);
        }
        if ($this->transferStore->consume($code, 'apply', $claims['target']) === null) {
            return $this->json(['result' => 'failed'], 403);
        }
        // Recheck after the source confirmation: a separate tab may have logged
        // into another account while the transfer was in progress.
        if ($this->hasOtherUser($request, $claims)) {
            return $this->receipt($claims, 'skipped');
        }
        $verify = $this->transferStore->issue('verify', $claims['target'], $claims);
        $response = $this->next($claims['targetBase'] . self::BRIDGE_PATH, ['stage' => 'verify', 'code' => $verify], true);
        $session = UserSession::createFromRecord($session->getIdentifier(), $session->toArray(), true);
        return SetCookieService::create($this->cookieName(), 'BE')->applyCookieToResponse(
            $response,
            $session,
            SetCookieBehavior::Send,
            $request->getAttribute('normalizedParams')
        );
    }

    private function verify(ServerRequestInterface $request, string $code, array $claims): ResponseInterface
    {
        if (!$this->hasTargetNonce($request, $claims)
            || $this->liveSource($claims) === null
            || $this->transferStore->consume($code, 'verify', $claims['target']) === null
        ) {
            return $this->json(['result' => 'failed', 'returnUrl' => $claims['returnUrl']], 403);
        }
        $current = $this->userSessionManager()->createFromRequestOrAnonymous($request, $this->cookieName());
        $outcome = hash_equals($claims['sid'], $current->getIdentifier()) ? 'synchronized' : 'failed';
        return $this->receipt($claims, $outcome)
            ->withAddedHeader('Set-Cookie', (string)$this->nonceCookie($claims, '', 1));
    }

    private function liveSource(array $claims): ?UserSession
    {
        $manager = $this->userSessionManager();
        try {
            $session = $manager->createSessionFromStorage($claims['sid']);
        } catch (SessionNotFoundException) {
            return null;
        }
        if ($session->isAnonymous() || $session->getUserId() !== $claims['userId'] || $manager->hasExpired($session)) {
            return null;
        }
        $state = $session->get('backend.sessionSynchronization');
        if (!is_array($state)
            || ($state['fingerprint'] ?? null) !== hash('sha256', $claims['sid'])
            || ($state['owner'] ?? null) !== $claims['source']
            || ($state['active']['flow'] ?? null) !== $claims['flow']
            || ($state['active']['origin'] ?? null) !== $claims['target']
            || ($state['active']['expires'] ?? null) !== $claims['deadline']
            || ($state['active']['mode'] ?? null) !== $claims['mode']
        ) {
            return null;
        }
        return $session;
    }

    private function hasOtherUser(ServerRequestInterface $request, array $claims): bool
    {
        $manager = $this->userSessionManager();
        $session = $manager->createFromRequestOrAnonymous($request, $this->cookieName());
        return !$session->isAnonymous() && !$manager->hasExpired($session) && $session->getUserId() !== $claims['userId'];
    }

    private function validClaims(ServerRequestInterface $request, array $claims, string $origin, string $stage): bool
    {
        foreach (['flow', 'source', 'target', 'sourceBase', 'targetBase', 'sid', 'sourceCookie', 'sourceHash', 'returnUrl', 'mode'] as $field) {
            if (!is_string($claims[$field] ?? null)) {
                return false;
            }
        }
        if (!is_int($claims['userId'] ?? null) || $claims['userId'] <= 0
            || !is_int($claims['deadline'] ?? null) || $claims['deadline'] <= $this->clock->now()->getTimestamp()
            || !preg_match('/\A[a-f0-9]{32}\z/', $claims['flow'])
            || !in_array($claims['mode'], ['frame', 'navigation'], true)
        ) {
            return false;
        }
        $sourceSide = in_array($stage, ['source', 'receipt'], true);
        if ($origin !== $claims[$sourceSide ? 'source' : 'target']) {
            return false;
        }
        $origins = $this->originResolver->resolve($request);
        $currentBase = $origins[$origin] ?? null;
        if ($currentBase === null || $currentBase !== $claims[$sourceSide ? 'sourceBase' : 'targetBase']) {
            return false;
        }
        $backendPath = substr($currentBase, strlen($origin));
        // The current origin can be an administration host absent from site
        // configs. Its authorization comes from the server-issued source flow.
        foreach (['source', 'target'] as $side) {
            if ($this->originResolver->canonicalOrigin($claims[$side]) !== $claims[$side]
                || $claims[$side . 'Base'] !== $claims[$side] . $backendPath
            ) {
                return false;
            }
        }
        if ($claims['returnUrl'] !== $claims['sourceBase']) {
            return false;
        }
        $expectedOrigin = match ($stage) {
            'bootstrap', 'apply' => $claims['source'],
            'source', 'receipt' => $claims['target'],
            default => $origin,
        };
        return hash_equals($expectedOrigin, $request->getHeaderLine('Origin'));
    }

    private function hasTargetNonce(ServerRequestInterface $request, array $claims): bool
    {
        $name = $claims['targetCookie'] ?? '';
        $hash = $claims['targetHash'] ?? '';
        $secret = $request->getCookieParams()[$name] ?? null;
        return is_string($name) && $name === '__Secure-typo3-sync-target-' . $claims['flow']
            && is_string($hash) && strlen($hash) === 64
            && is_string($secret) && hash_equals($hash, hash('sha256', $secret));
    }

    private function nonceCookie(array $claims, string $value, ?int $expires = null): Cookie
    {
        return new Cookie(
            $claims['targetCookie'],
            $value,
            $expires ?? $claims['deadline'],
            parse_url($claims['targetBase'], PHP_URL_PATH),
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT
        );
    }

    private function receipt(array $claims, string $outcome): ResponseInterface
    {
        $claims['outcome'] = $outcome;
        $receipt = $this->transferStore->issue('receipt', $claims['source'], $claims);
        return $this->next($claims['sourceBase'] . self::BRIDGE_PATH, ['stage' => 'receipt', 'code' => $receipt]);
    }

    private function page(array $claims, string $url, array $fields): ResponseInterface
    {
        return $this->bridgePage->render(
            ['url' => $url, 'fields' => $fields, 'returnUrl' => $claims['returnUrl']],
            $claims['source'],
            $claims['flow'],
            [$claims['source'], $claims['target']]
        );
    }

    private function next(string $url, array $fields, bool $request = false): ResponseInterface
    {
        return $this->json(['next' => ['url' => $url, 'fields' => $fields, 'request' => $request]]);
    }

    private function json(array $data, int $status = 200): ResponseInterface
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    private function cookieName(): string
    {
        return BackendUserAuthentication::getCookieName();
    }

    private function userSessionManager(): UserSessionManager
    {
        return UserSessionManager::create('BE', null, $this->sessionManager, null, $this->clock);
    }
}
