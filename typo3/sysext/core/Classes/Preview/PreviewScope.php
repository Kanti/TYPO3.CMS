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

use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Http\Uri;

/**
 * The sites and hosts a preview token is valid on. It is not part of the token:
 * the backend and the site resolve it from the rights the editor has now, see
 * PreviewScopeResolver.
 *
 * @internal
 */
final readonly class PreviewScope
{
    /**
     * @param list<array{site: string, host: string, origin: string}> $bases
     *        the bases of the site languages: site identifier, host (with non-default
     *        port) and origin
     */
    public function __construct(private array $bases) {}

    public function isEmpty(): bool
    {
        return $this->bases === [];
    }

    /**
     * @return list<string>
     */
    public function getHosts(): array
    {
        return array_values(array_unique(array_column($this->bases, 'host')));
    }

    /**
     * @return list<string>
     */
    public function getOrigins(): array
    {
        return array_values(array_filter(array_unique(array_column($this->bases, 'origin'))));
    }

    public function includesSite(string $identifier): bool
    {
        return in_array($identifier, array_column($this->bases, 'site'), true);
    }

    /**
     * The hosts whose sites have a base on HTTPS and none on plain HTTP: an address on plain
     * HTTP there would carry the token in the clear before the site redirects to HTTPS.
     *
     * @return list<string>
     */
    public function getHttpsOnlyHosts(): array
    {
        $plainHosts = [];
        $secureHosts = [];
        foreach ($this->bases as $base) {
            if (str_starts_with($base['origin'], 'https://')) {
                $secureHosts[] = $base['host'];
            } else {
                $plainHosts[] = $base['host'];
            }
        }
        return array_values(array_unique(array_diff($secureHosts, $plainHosts)));
    }

    public function allowsHost(string $host): bool
    {
        return in_array(strtolower($host), array_column($this->bases, 'host'), true);
    }

    public function allowsUri(UriInterface $uri): bool
    {
        if (!in_array($uri->getScheme(), ['http', 'https'], true)) {
            return false;
        }
        if ($uri->getUserInfo() !== '') {
            return false;
        }
        $host = self::hostOf($uri);
        if ($uri->getScheme() === 'http' && in_array($host, $this->getHttpsOnlyHosts(), true)) {
            return false;
        }
        return $this->allowsHost($host);
    }

    /**
     * Whether an address as it is written in a link, a form or a redirect leads to a host
     * of the scope. A relative address is resolved against the address of the request. An
     * address of another scheme, such as mailto: or tel:, or a malformed one never does.
     */
    public function allowsUrl(string $url, UriInterface $base): bool
    {
        if (!self::isReadAsWritten($url)) {
            return false;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }
        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return $this->allowsUri(UriResolver::resolve($base, $uri));
    }

    /**
     * Browsers read an address differently from a URI parser where it holds a tab or a
     * line break, which they remove, a control character or a space at either end, which
     * they strip, or a backslash before its query, which they take for a slash:
     * "/\evil.example/x" leads to the host evil.example. The token goes to no such address.
     */
    private static function isReadAsWritten(string $url): bool
    {
        if (strpbrk($url, "\t\n\r") !== false) {
            return false;
        }
        if ($url !== trim($url, "\x00..\x20")) {
            return false;
        }
        return !str_contains(substr($url, 0, strcspn($url, '?#')), '\\');
    }

    public static function hostOf(UriInterface $uri): string
    {
        $host = self::normalizeHost($uri->getHost());
        return $uri->getPort() !== null ? $host . ':' . $uri->getPort() : $host;
    }

    /**
     * The host in lower case and, for an internationalized domain name, in its ASCII form:
     * browsers send that form, and the site matching of TYPO3 compares it.
     */
    public static function normalizeHost(string $host): string
    {
        if ($host === '') {
            return '';
        }
        $asciiHost = idn_to_ascii($host);
        return strtolower($asciiHost === false ? $host : $asciiHost);
    }
}
