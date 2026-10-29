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

use Psr\Http\Message\UriInterface;

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
        return $this->allowsHost(self::hostOf($uri));
    }

    public static function hostOf(UriInterface $uri): string
    {
        $host = strtolower($uri->getHost());
        return $uri->getPort() !== null ? $host . ':' . $uri->getPort() : $host;
    }
}
