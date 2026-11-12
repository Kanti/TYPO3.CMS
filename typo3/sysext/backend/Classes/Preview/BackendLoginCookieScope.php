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

namespace TYPO3\CMS\Backend\Preview;

use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\CookieScopeTrait;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Preview\PreviewScope;

/**
 * Says whether the backend login cookie reaches the host of a frontend address,
 * so a preview there can rely on the backend login of the browser.
 *
 * @internal
 */
final class BackendLoginCookieScope implements LoggerAwareInterface
{
    use CookieScopeTrait;
    use LoggerAwareTrait;

    private string $loginType = 'BE';

    public function reaches(NormalizedParams $backendRequest, UriInterface $frontendUri): bool
    {
        $host = PreviewScope::normalizeHost($frontendUri->getHost());
        if ($host === '') {
            return true;
        }
        $scope = $this->getCookieScope($backendRequest);
        $domain = PreviewScope::normalizeHost($scope->domain);
        if ($host === $domain) {
            return true;
        }
        if ($scope->hostOnly) {
            return false;
        }
        return str_ends_with($host, '.' . $domain);
    }
}
