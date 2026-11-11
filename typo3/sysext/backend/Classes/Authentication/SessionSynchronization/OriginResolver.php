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

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final readonly class OriginResolver
{
    public function __construct(
        private SiteFinder $siteFinder,
        private BackendEntryPointResolver $backendEntryPointResolver,
    ) {}

    /**
     * All targets serve the same installation, including its backend entry point.
     * Site and language paths identify frontend content, not backend installations.
     *
     * @return array<string, string> Canonical HTTPS origin => absolute backend base URI
     */
    public function resolve(ServerRequestInterface $request): array
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            return [];
        }
        $currentOrigin = $this->canonicalOrigin($normalizedParams->getRequestHost());
        if ($currentOrigin === null) {
            return [];
        }
        $backendPath = $this->backendEntryPointResolver->getPathFromRequest($request);
        $origins = [$currentOrigin => $currentOrigin . $backendPath];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $bases = [$site->getBase()];
            foreach ($site->getAllLanguages() as $language) {
                $bases[] = $language->getBase();
            }
            foreach ($bases as $base) {
                if ($base->getUserInfo() !== '' || $base->getQuery() !== '' || $base->getFragment() !== '') {
                    continue;
                }
                if ($base->getScheme() === '' && $base->getHost() === '') {
                    $origin = $currentOrigin;
                } else {
                    $origin = $this->canonicalOrigin($base->getScheme() === '' ? 'https:' . $base : $base);
                }
                if ($origin !== null) {
                    $origins[$origin] = $origin . $backendPath;
                }
            }
        }
        return $origins;
    }

    public function canonicalOrigin(string|UriInterface $url): ?string
    {
        $url = (string)$url;
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return null;
        }
        $host = $this->canonicalHost($parts['host']);
        $port = $parts['port'] ?? 443;
        if ($host === null || $port < 1 || $port > 65535) {
            return null;
        }
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port);
    }

    private function canonicalHost(string $host): ?string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $address = substr($host, 1, -1);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $segments = array_map(dechex(...), array_values(unpack('n*', inet_pton($address))));
            $longestStart = 0;
            $longestLength = 0;
            for ($start = 0; $start < 8; $start++) {
                $length = 0;
                while ($start + $length < 8 && $segments[$start + $length] === '0') {
                    $length++;
                }
                if ($length > $longestLength) {
                    $longestStart = $start;
                    $longestLength = $length;
                }
            }
            if ($longestLength > 1) {
                return '[' . implode(':', array_slice($segments, 0, $longestStart)) . '::'
                    . implode(':', array_slice($segments, $longestStart + $longestLength)) . ']';
            }
            return '[' . implode(':', $segments) . ']';
        }
        $host = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
        if ($host === false || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }
        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        // Browsers interpret numeric final labels as IPv4, including legacy octal/hex forms.
        $lastLabel = substr(strrchr('.' . rtrim($host, '.'), '.'), 1);
        if (ctype_digit($lastLabel) || preg_match('/^0x[0-9a-f]+$/', $lastLabel)) {
            return null;
        }
        return $host;
    }
}
