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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Resolves the sites and hosts an editor may preview now: sites that overlap the web
 * mounts, in the languages the editor has access to, with an HTTP(S) base. A visitor
 * token is valid on every site of the installation, in the languages a visitor reaches.
 * Bases without a host are resolved against the backend that signs the token. The
 * backend resolves it when it signs or renews a token, the site again on every request
 * against the backend origin signed in the token, so both get the same result.
 *
 * @internal
 */
final readonly class PreviewHostsResolver
{
    public function __construct(private SiteFinder $siteFinder) {}

    public function resolve(BackendUserAuthentication $user, UriInterface $backendUri): PreviewHosts
    {
        $mounts = $user->isAdmin() ? [0] : array_map(intval(...), $user->getWebmounts());
        if ($mounts === []) {
            return new PreviewHosts([]);
        }
        $bases = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (!$this->siteOverlapsMounts($site, $mounts)) {
                continue;
            }
            $languages = array_filter($site->getAllLanguages(), $user->checkLanguageAccess(...));
            array_push($bases, ...$this->resolveBases($site, $languages, $backendUri));
        }
        return new PreviewHosts($bases);
    }

    /**
     * The sites and hosts of a visitor token, which needs no editor: every site of the
     * installation, in the languages a visitor reaches, the enabled ones.
     */
    public function resolveForVisitor(UriInterface $backendUri): PreviewHosts
    {
        $bases = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            array_push($bases, ...$this->resolveBases($site, $site->getLanguages(), $backendUri));
        }
        return new PreviewHosts($bases);
    }

    /**
     * @param array<int, SiteLanguage> $languages
     * @return list<array{site: string, host: string, origin: string}>
     */
    private function resolveBases(Site $site, array $languages, UriInterface $backendUri): array
    {
        $bases = [];
        foreach ($languages as $language) {
            $base = UriResolver::resolve($backendUri, $language->getBase());
            if (!in_array($base->getScheme(), ['http', 'https'], true)) {
                continue;
            }
            if ($base->getHost() === '') {
                continue;
            }
            $base = $base->withHost(PreviewHosts::normalizeHost($base->getHost()));
            $bases[] = [
                'site' => $site->getIdentifier(),
                'host' => PreviewHosts::hostOf($base),
                'origin' => (string)$base->withPath('')->withQuery('')->withFragment('')->withUserInfo(''),
            ];
        }
        return $bases;
    }

    /**
     * @param list<int> $mounts
     */
    private function siteOverlapsMounts(Site $site, array $mounts): bool
    {
        if (in_array(0, $mounts, true)) {
            return true;
        }
        $rootPageId = $site->getRootPageId();
        $rootlineOf = static function (int $pageId): array {
            try {
                return array_map(intval(...), array_column(GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get(), 'uid'));
            } catch (RootLineException) {
                return [];
            }
        };
        if (array_intersect($mounts, $rootlineOf($rootPageId)) !== []) {
            return true;
        }
        foreach ($mounts as $mount) {
            if (in_array($rootPageId, $rootlineOf($mount), true)) {
                return true;
            }
        }
        return false;
    }
}
