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
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * @internal
 */
final readonly class Coordinator
{
    private const SESSION_KEY = 'backend.sessionSynchronization';
    private const FLOW_LIFETIME = 120;

    public function __construct(
        private OriginResolver $originResolver,
        private TransferStore $transferStore,
        private ClockInterface $clock,
    ) {}

    public function control(ServerRequestInterface $request, BackendUserAuthentication $user): ResponseInterface
    {
        $origin = $this->authenticatedOrigin($request, $user);
        if ($origin === null) {
            return $this->json(['error' => 'Invalid synchronization request.'], 403);
        }
        $origins = $this->originResolver->resolve($request);
        if (!isset($origins[$origin])) {
            return $this->json(['enabled' => false, 'pending' => false, 'results' => []]);
        }
        $state = $this->state($user, $origin, $origins);
        $data = (array)$request->getParsedBody();
        $action = $data['action'] ?? 'status';
        if ($state['owner'] !== $origin) {
            return $action === 'next' ? $this->json(null) : $this->json($this->status($state, false));
        }
        if ($action === 'status') {
            return $this->json($this->status($state, count($origins) > 1));
        }
        if ($action === 'retry') {
            $mode = $data['mode'] ?? 'frame';
            if (!in_array($mode, ['frame', 'navigation'], true)) {
                return $this->json(['error' => 'Invalid synchronization mode.'], 400);
            }
            $retryStatus = $mode === 'navigation' ? 'navigation-required' : 'pending';
            $active = $state['active'];
            if ($active !== null) {
                if (isset($state['results'][$active['origin']])) {
                    $state['results'][$active['origin']] = $retryStatus;
                }
                $state['active'] = null;
            }
            foreach ($state['results'] as &$result) {
                if ($result === 'failed' || $mode === 'navigation' && $result === 'pending') {
                    $result = $retryStatus;
                }
            }
            unset($result);
            $this->save($user, $state);
            $response = $this->json($this->status($state, count($origins) > 1));
            return $active === null ? $response : $this->withSourceCookie($response, $active['flow'], '', $origins[$origin], 1);
        }
        if ($action === 'failed') {
            $active = $state['active'];
            if ($active === null || !is_string($data['flow'] ?? null) || !hash_equals($active['flow'], $data['flow'])) {
                return $this->json(['error' => 'Synchronization flow is no longer active.'], 409);
            }
            $navigationRequired = ($data['result'] ?? '') === 'navigation-required' || ($data['navigationRequired'] ?? '') === '1';
            $state['results'][$active['origin']] = $navigationRequired && $active['mode'] === 'frame' ? 'navigation-required' : 'failed';
            $state['active'] = null;
            $this->save($user, $state);
            return $this->withSourceCookie($this->json($this->status($state, count($origins) > 1)), $active['flow'], '', $origins[$origin], 1);
        }
        if ($action !== 'next') {
            return $this->json(['error' => 'Unknown synchronization action.'], 400);
        }
        $mode = $data['mode'] ?? 'frame';
        if (!in_array($mode, ['frame', 'navigation'], true)) {
            return $this->json(['error' => 'Invalid synchronization mode.'], 400);
        }
        if ($state['active'] !== null) {
            return $this->json(null);
        }
        $expectedStatus = $mode === 'frame' ? 'pending' : 'navigation-required';
        $target = array_search($expectedStatus, $state['results'], true);
        if (!is_string($target) || !isset($origins[$target])) {
            return $this->json(null);
        }
        $flow = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $deadline = $this->clock->now()->getTimestamp() + self::FLOW_LIFETIME;
        $claims = [
            'flow' => $flow,
            'source' => $origin,
            'target' => $target,
            'sourceBase' => $origins[$origin],
            'targetBase' => $origins[$target],
            'sid' => $user->getSession()->getIdentifier(),
            'userId' => $user->getUserId(),
            'sourceCookie' => $this->sourceCookieName($flow),
            'sourceHash' => hash('sha256', $secret),
            'mode' => $mode,
            'returnUrl' => $origins[$origin],
            'deadline' => $deadline,
        ];
        $this->transferStore->collectGarbage();
        $code = $this->transferStore->issue('bootstrap', $target, $claims);
        $state['results'][$target] = 'in-progress';
        $state['active'] = ['flow' => $flow, 'origin' => $target, 'expires' => $deadline, 'mode' => $mode];
        $this->save($user, $state);
        return $this->withSourceCookie($this->json([
            'flow' => $flow,
            'target' => $target,
            'url' => $origins[$target] . 'session/synchronize/bridge',
            'fields' => ['stage' => 'bootstrap', 'code' => $code],
            'sourceOrigin' => $origin,
            'returnUrl' => $origins[$origin],
        ]), $flow, $secret, $origins[$origin], $deadline);
    }

    public function confirm(ServerRequestInterface $request, BackendUserAuthentication $user): ResponseInterface
    {
        $validated = $this->validateTransfer($request, $user, 'confirm');
        if ($validated === null) {
            return $this->json(['error' => 'Synchronization confirmation is invalid or expired.'], 403);
        }
        [$code, $claims] = $validated;
        if (!is_string($claims['targetCookie'] ?? null) || !is_string($claims['targetHash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $claims['targetHash']) !== 1
            || $this->transferStore->consume($code, 'confirm', $claims['source']) !== $claims
        ) {
            return $this->json(['error' => 'Synchronization confirmation is invalid or expired.'], 403);
        }
        return $this->json(['next' => [
            'url' => $claims['targetBase'] . 'session/synchronize/bridge',
            'fields' => ['stage' => 'apply', 'code' => $this->transferStore->issue('apply', $claims['target'], $claims)],
        ]]);
    }

    public function receipt(ServerRequestInterface $request, BackendUserAuthentication $user): ResponseInterface
    {
        $validated = $this->validateTransfer($request, $user, 'receipt');
        if ($validated === null) {
            return $this->json(['error' => 'Synchronization receipt is invalid or expired.'], 403);
        }
        [$code, $claims, $state] = $validated;
        $outcome = $claims['outcome'] ?? '';
        if (!in_array($outcome, ['synchronized', 'skipped', 'failed'], true)
            || $this->transferStore->consume($code, 'receipt', $claims['source']) !== $claims
        ) {
            return $this->json(['error' => 'Synchronization receipt is invalid or expired.'], 403);
        }
        $state['results'][$claims['target']] = $outcome;
        $state['active'] = null;
        $this->save($user, $state);
        return $this->withSourceCookie($this->json([
            'result' => $outcome,
            'returnUrl' => $claims['returnUrl'],
        ]), $claims['flow'], '', $claims['sourceBase'], 1);
    }

    private function validateTransfer(ServerRequestInterface $request, BackendUserAuthentication $user, string $purpose): ?array
    {
        $origin = $this->authenticatedOrigin($request, $user);
        $code = ((array)$request->getParsedBody())['code'] ?? null;
        if ($origin === null || !is_string($code)) {
            return null;
        }
        $claims = $this->transferStore->inspect($code, $purpose, $origin);
        if ($claims === null) {
            return null;
        }
        $origins = $this->originResolver->resolve($request);
        $state = $user->getSessionData(self::SESSION_KEY);
        $active = is_array($state) ? ($state['active'] ?? null) : null;
        if (!is_array($active)
            || ($state['fingerprint'] ?? null) !== hash('sha256', $user->getSession()->getIdentifier())
            || ($state['owner'] ?? null) !== $origin
            || ($claims['source'] ?? null) !== $origin
            || !is_string($claims['target'] ?? null)
            || !isset($origins[$origin], $origins[$claims['target']])
            || ($claims['sourceBase'] ?? null) !== $origins[$origin]
            || ($claims['targetBase'] ?? null) !== $origins[$claims['target']]
            || ($claims['returnUrl'] ?? null) !== $origins[$origin]
            || ($claims['sid'] ?? null) !== $user->getSession()->getIdentifier()
            || ($claims['userId'] ?? null) !== $user->getUserId()
            || ($claims['flow'] ?? null) !== ($active['flow'] ?? null)
            || ($claims['target'] ?? null) !== ($active['origin'] ?? null)
            || ($claims['mode'] ?? null) !== ($active['mode'] ?? null)
            || !is_int($claims['deadline'] ?? null)
            || $claims['deadline'] !== ($active['expires'] ?? null)
            || $claims['deadline'] <= $this->clock->now()->getTimestamp()
            || !is_string($claims['flow'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/', $claims['flow']) !== 1
            || ($claims['sourceCookie'] ?? null) !== $this->sourceCookieName($claims['flow'])
            || !is_string($claims['sourceHash'] ?? null)
        ) {
            return null;
        }
        $secret = $request->getCookieParams()[$claims['sourceCookie']] ?? null;
        if (!is_string($secret) || preg_match('/\A[a-f0-9]{64}\z/', $secret) !== 1 || !hash_equals($claims['sourceHash'], hash('sha256', $secret))) {
            return null;
        }
        return [$code, $claims, $state];
    }

    private function authenticatedOrigin(ServerRequestInterface $request, BackendUserAuthentication $user): ?string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($request->getMethod() !== 'POST' || !$normalizedParams instanceof NormalizedParams
            || $user->getSession()->isAnonymous()
            || $user->getUserId() !== $user->getSession()->getUserId()
        ) {
            return null;
        }
        $origin = $this->originResolver->canonicalOrigin($normalizedParams->getRequestHost());
        return $origin !== null && $request->getHeaderLine('Origin') === $origin ? $origin : null;
    }

    private function state(BackendUserAuthentication $user, string $origin, array $origins): array
    {
        $state = $user->getSessionData(self::SESSION_KEY);
        if (!is_array($state) || ($state['fingerprint'] ?? null) !== hash('sha256', $user->getSession()->getIdentifier())
            || !is_string($state['owner'] ?? null) || !is_array($state['results'] ?? null)
        ) {
            $state = [
                'fingerprint' => hash('sha256', $user->getSession()->getIdentifier()),
                'owner' => $origin,
                'results' => array_fill_keys(array_keys($origins), 'pending'),
                'active' => null,
            ];
            $state['results'][$origin] = 'synchronized';
            $this->save($user, $state);
        }
        if ($state['owner'] === $origin) {
            $state['results'] = array_intersect_key($state['results'], $origins) + array_fill_keys(array_keys($origins), 'pending');
            if (is_array($state['active'] ?? null) && ($state['active']['expires'] ?? 0) <= $this->clock->now()->getTimestamp()) {
                if (isset($state['results'][$state['active']['origin']])) {
                    $state['results'][$state['active']['origin']] = 'failed';
                }
                $state['active'] = null;
            }
            $this->save($user, $state);
        }
        return $state;
    }

    private function save(BackendUserAuthentication $user, array $state): void
    {
        $user->setAndSaveSessionData(self::SESSION_KEY, $state);
    }

    private function status(array $state, bool $enabled): array
    {
        $results = [];
        foreach ($state['results'] as $origin => $status) {
            $results[] = ['origin' => $origin, 'status' => $status === 'in-progress' ? 'pending' : $status];
        }
        return [
            'enabled' => $enabled,
            'pending' => $enabled && array_intersect(['pending', 'navigation-required', 'in-progress'], $state['results']) !== [],
            'results' => $results,
        ];
    }

    private function sourceCookieName(string $flow): string
    {
        return '__Secure-typo3_be_sync_' . $flow;
    }

    private function withSourceCookie(ResponseInterface $response, string $flow, string $secret, string $base, int $expires): ResponseInterface
    {
        $cookie = new Cookie($this->sourceCookieName($flow), $secret, $expires, parse_url($base, PHP_URL_PATH) ?: '/', null, true, true, false, Cookie::SAMESITE_STRICT);
        return $response->withAddedHeader('Set-Cookie', (string)$cookie);
    }

    private function json(?array $data, int $status = 200): ResponseInterface
    {
        $response = new JsonResponse($data, $status, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        if ($data === null) {
            $response->getBody()->write('null');
        }
        return $response;
    }
}
