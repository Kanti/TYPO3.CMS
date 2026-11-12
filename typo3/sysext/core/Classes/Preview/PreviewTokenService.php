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

namespace TYPO3\CMS\Core\Preview;

use Firebase\JWT\ExpiredException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Security\JwtTrait;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Signs and verifies short-lived preview tokens. A token carries the editor it
 * was signed for and the whole preview profile, verifying it needs no session.
 * It names no hosts, so its size does not grow with the sites of an installation:
 * the site resolves them from the editor on every request. A visitor token only
 * shows the pages as a visitor sees them; its editor decides only where it is valid.
 *
 * @internal
 */
final readonly class PreviewTokenService
{
    use JwtTrait;

    public const PARAMETER = '__t3ct';
    public const LIFETIME = 900;
    private const TYPE = 'preview';
    /**
     * Seconds the clock of a site may lag behind the clock of the backend that signed a token
     */
    private const CLOCK_SKEW = 60;

    public function __construct(private ClockInterface $clock) {}

    /**
     * A token of the editor makes the editor the backend user of the request (claim sub).
     * A visitor token names the editor in another claim (scp), which no part of the site
     * takes for a login.
     */
    public function sign(PreviewProfile $profile, string $backendOrigin, string $userAgent, int $editorId, bool $visitor = false): PreviewToken
    {
        if ($editorId <= 0) {
            throw new \InvalidArgumentException('A preview token requires a positive user id, got ' . $editorId . '.', 1794225101);
        }
        $issuedAt = $this->clock->now()->getTimestamp();
        $claims = [
            'typ' => self::TYPE,
            'iat' => $issuedAt,
            'nbf' => $issuedAt - self::CLOCK_SKEW,
            'exp' => $issuedAt + self::LIFETIME,
            'uah' => self::hashUserAgent($userAgent),
            'bk' => $backendOrigin,
            'prf' => $profile->toArray(),
        ];
        $claims[$visitor ? 'scp' : 'sub'] = $editorId;
        $jwt = self::encodeHashSignedJwt($claims, self::createSigningKeyFromEncryptionKey());
        return new PreviewToken($jwt, $profile, $backendOrigin, $claims['exp'], $editorId, $visitor);
    }

    /**
     * Checks signature, expiry and browser. Whether the token is valid on the host and
     * site of the request is up to the site, which resolves them from the editor.
     *
     * @throws PreviewTokenRejectedException
     */
    public function verify(string $jwt, string $userAgent): PreviewToken
    {
        [$token, $userAgentHash] = $this->decode($jwt, false);
        if ($token->expires <= $this->clock->now()->getTimestamp()) {
            throw new PreviewTokenRejectedException(PreviewRejection::Expired, 'Preview token expired', 1793265768, null, $token);
        }
        if (!hash_equals($userAgentHash, self::hashUserAgent($userAgent))) {
            throw new PreviewTokenRejectedException(PreviewRejection::OtherBrowser, 'Preview token belongs to another browser', 1793265771, null, $token);
        }
        return $token;
    }

    /**
     * Reads a token this installation signed for this browser, also after it expired,
     * so a fresh token keeps the simulation of the previous one and whether it shows
     * the pages to the editor or as a visitor sees them.
     *
     * @throws PreviewTokenRejectedException
     */
    public function readForRenewal(string $jwt, string $userAgent): PreviewToken
    {
        [$token, $userAgentHash] = $this->decode($jwt, true);
        if (!hash_equals($userAgentHash, self::hashUserAgent($userAgent))) {
            throw new PreviewTokenRejectedException(PreviewRejection::OtherBrowser, 'Preview token belongs to another browser', 1793276925, null, $token);
        }
        return $token;
    }

    /**
     * @return array{PreviewToken, string} the token and the hash of the browser it was signed for
     * @throws PreviewTokenRejectedException
     */
    private function decode(string $jwt, bool $allowExpired): array
    {
        try {
            $claims = self::decodeJwt($jwt, self::createSigningKeyFromEncryptionKey(), true);
        } catch (ExpiredException $exception) {
            $claims = json_decode(json_encode($exception->getPayload(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            if (!$allowExpired) {
                throw new PreviewTokenRejectedException(PreviewRejection::Expired, 'Preview token expired', 1793265765, $exception, $this->createToken($jwt, $claims));
            }
        } catch (\Throwable $exception) {
            throw new PreviewTokenRejectedException(PreviewRejection::Invalid, 'Preview token invalid', 1793265766, $exception);
        }
        $token = $this->createToken($jwt, $claims);
        if ($token === null) {
            throw new PreviewTokenRejectedException(PreviewRejection::Invalid, 'Preview token claims invalid', 1793265767);
        }
        return [$token, $claims['uah']];
    }

    /**
     * Replaces the token of the address. The rest of the query keeps its bytes: flags,
     * repeated names, `+` and the encoding of the values, as signed addresses and
     * redirects that compare the query rely on them.
     */
    public static function withToken(UriInterface $uri, string $jwt): UriInterface
    {
        $query = self::withoutToken($uri)->getQuery();
        return $uri->withQuery(($query === '' ? '' : $query . '&') . self::PARAMETER . '=' . rawurlencode($jwt));
    }

    /**
     * Removes the token from the address and keeps the bytes of the rest of the query.
     * An address without token is returned as it is.
     */
    public static function withoutToken(UriInterface $uri): UriInterface
    {
        $query = $uri->getQuery();
        $kept = self::withoutParameters($query, [self::PARAMETER]);
        return $kept === $query ? $uri : $uri->withQuery($kept);
    }

    /**
     * Replaces the token of an address as it is written in a link, a form or a redirect,
     * relative or absolute. No URI object is built from it, which would encode brackets
     * or drop a query "0": every byte besides the token stays as it is.
     */
    public static function addToUrl(string $url, string $jwt): string
    {
        [$address, $query, $fragment] = self::splitUrl($url);
        $query = self::withoutParameters($query ?? '', [self::PARAMETER]);
        return $address . '?' . ($query === '' ? '' : $query . '&') . self::PARAMETER . '=' . rawurlencode($jwt) . $fragment;
    }

    /**
     * Removes the token from an address as it is written and keeps every other byte of it.
     * An address without token is returned as it is.
     */
    public static function removeFromUrl(string $url): string
    {
        [$address, $query, $fragment] = self::splitUrl($url);
        if ($query === null) {
            return $url;
        }
        $kept = self::withoutParameters($query, [self::PARAMETER]);
        if ($kept === $query) {
            return $url;
        }
        return $address . ($kept === '' ? '' : '?' . $kept) . $fragment;
    }

    /**
     * Removes the pairs with one of the names from a query and keeps the bytes of every
     * other pair. A pair counts by the name PHP gives it, as the site reads the query
     * that way: "__t3ct[]", " __t3ct" and "._t3ct" are all "__t3ct".
     *
     * @param list<string> $names
     */
    public static function withoutParameters(string $query, array $names): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = explode('&', $query);
        $kept = array_filter($pairs, static function (string $pair) use ($names): bool {
            parse_str(explode('=', $pair, 2)[0], $parameters);
            return !in_array((string)array_key_first($parameters), $names, true);
        });
        return count($kept) === count($pairs) ? $query : implode('&', $kept);
    }

    /**
     * @return array{0: string, 1: string|null, 2: string} the address up to its query, the
     *         query without "?" or null if there is none, and the fragment with its "#"
     */
    private static function splitUrl(string $url): array
    {
        $fragmentStart = strpos($url, '#');
        $fragment = $fragmentStart === false ? '' : substr($url, $fragmentStart);
        $address = $fragmentStart === false ? $url : substr($url, 0, $fragmentStart);
        $queryStart = strpos($address, '?');
        if ($queryStart === false) {
            return [$address, null, $fragment];
        }
        return [substr($address, 0, $queryStart), substr($address, $queryStart + 1), $fragment];
    }

    private function createToken(string $jwt, array $claims): ?PreviewToken
    {
        if (($claims['typ'] ?? null) !== self::TYPE) {
            return null;
        }
        if (!is_int($claims['exp'] ?? null)) {
            return null;
        }
        if (!is_string($claims['uah'] ?? null)) {
            return null;
        }
        if (!is_string($claims['bk'] ?? null)) {
            return null;
        }
        if (!preg_match('#^https?://[^/?\#@]+$#', $claims['bk'])) {
            return null;
        }
        if (!is_array($claims['prf'] ?? null)) {
            return null;
        }
        $profile = PreviewProfile::fromArray($claims['prf']);
        if ($profile === null) {
            return null;
        }
        if (array_key_exists('sub', $claims)) {
            if (!is_int($claims['sub'])) {
                return null;
            }
            if ($claims['sub'] <= 0) {
                return null;
            }
            if (array_key_exists('scp', $claims)) {
                return null;
            }
            return new PreviewToken($jwt, $profile, $claims['bk'], $claims['exp'], $claims['sub']);
        }
        if (!$profile->isVisitorView()) {
            return null;
        }
        if (!is_int($claims['scp'] ?? null)) {
            return null;
        }
        if ($claims['scp'] <= 0) {
            return null;
        }
        return new PreviewToken($jwt, $profile, $claims['bk'], $claims['exp'], $claims['scp'], true);
    }

    private static function hashUserAgent(string $userAgent): string
    {
        return StringUtility::base64urlEncode(substr(hash('sha256', $userAgent, true), 0, 16));
    }
}
