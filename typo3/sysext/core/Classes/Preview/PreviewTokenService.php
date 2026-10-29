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
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Security\JwtTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/** @internal */
#[Autoconfigure(public: true)]
final readonly class PreviewTokenService
{
    use JwtTrait;

    public const LIFETIME = 900;

    public function __construct(private SiteFinder $siteFinder, private ConnectionPool $connectionPool) {}

    public function issue(BackendUserAuthentication $user, UriInterface $backendUri, array $settings): string
    {
        $mounts = $user->isAdmin() ? [0] : GeneralUtility::intExplode(',', $user->groupData['webmounts'] ?? '', true);
        $scope = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (!$this->siteOverlapsMounts($site, $mounts)) {
                continue;
            }
            foreach ($site->getAllLanguages() as $language) {
                if (!$user->checkLanguageAccess($language)) {
                    continue;
                }
                $base = UriResolver::resolve($backendUri, $language->getBase());
                if (in_array($base->getScheme(), ['https', 'http'], true) && $base->getUserInfo() === '') {
                    $scope[$site->getIdentifier()][$language->getLanguageId()] = (string)$base;
                }
            }
        }
        $claims = [
            'typ' => 'typo3-preview',
            'sub' => (int)$user->user['uid'],
            'parent' => (string)$backendUri->withPath('')->withQuery('')->withFragment(''),
            'scope' => $scope,
            'mounts' => $mounts,
            'workspace' => $settings['workspace'] ?? $user->workspace,
            'date' => $settings['date'] ?? 0,
            'groups' => $settings['groups'] ?? [],
            'visibility' => $settings['visibility'] ?? [
                'hiddenPages' => true,
                'hiddenContent' => false,
                'scheduledRecords' => false,
                'disabledLanguages' => true,
            ],
        ];
        $this->assertPermissions($claims, $user);
        return $this->sign($claims);
    }

    public function decode(string $token, bool $allowExpired = false): array
    {
        try {
            $claims = self::decodeJwt($token, self::createSigningKeyFromEncryptionKey(), true);
        } catch (ExpiredException $exception) {
            if (!$allowExpired) {
                throw $exception;
            }
            // Only an authenticated backend may renew an expired, signature-verified token.
            $claims = json_decode(json_encode($exception->getPayload(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }
        if (($claims['typ'] ?? null) !== 'typo3-preview'
            || !is_int($claims['sub'] ?? null) || $claims['sub'] <= 0
            || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
            || $claims['exp'] - $claims['iat'] !== self::LIFETIME || $claims['iat'] > time()
            || (!$allowExpired && $claims['exp'] <= time())
            || !is_string($claims['parent'] ?? null)
            || !is_array($claims['scope'] ?? null) || $claims['scope'] === []
            || !is_array($claims['mounts'] ?? null) || $claims['mounts'] === [] || !array_is_list($claims['mounts'])
            || !is_int($claims['workspace'] ?? null) || $claims['workspace'] < 0
            || !is_int($claims['date'] ?? null) || $claims['date'] < 0
            || !is_array($claims['groups'] ?? null) || !array_is_list($claims['groups'])
        ) {
            throw new \UnexpectedValueException('Invalid preview claims', 1793200801);
        }
        $parent = new Uri($claims['parent']);
        if (!in_array($parent->getScheme(), ['https', 'http'], true) || $parent->getHost() === ''
            || $parent->getUserInfo() !== '' || $parent->getPath() !== '' || $parent->getQuery() !== '' || $parent->getFragment() !== ''
        ) {
            throw new \UnexpectedValueException('Invalid preview parent', 1793200814);
        }
        foreach ($claims['mounts'] as $mount) {
            if (!is_int($mount) || $mount < 0) {
                throw new \UnexpectedValueException('Invalid preview mounts', 1793200815);
            }
        }
        foreach ($claims['scope'] as $languages) {
            if (!is_array($languages) || $languages === []) {
                throw new \UnexpectedValueException('Invalid preview scope', 1793200816);
            }
            foreach ($languages as $languageId => $base) {
                if (!is_int($languageId) || $languageId < 0 || !is_string($base)) {
                    throw new \UnexpectedValueException('Invalid preview language', 1793200817);
                }
                $uri = new Uri($base);
                if (!in_array($uri->getScheme(), ['https', 'http'], true) || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
                    throw new \UnexpectedValueException('Invalid preview base', 1793200818);
                }
            }
        }
        foreach (['hiddenPages', 'hiddenContent', 'scheduledRecords', 'disabledLanguages'] as $flag) {
            if (!is_bool($claims['visibility'][$flag] ?? null)) {
                throw new \UnexpectedValueException('Invalid preview visibility', 1793200802);
            }
        }
        foreach ($claims['groups'] as $group) {
            if (!is_int($group) || $group < 1) {
                throw new \UnexpectedValueException('Invalid preview group', 1793200803);
            }
        }
        return $claims;
    }

    public function renew(string $token, BackendUserAuthentication $user): string
    {
        $claims = $this->decode($token, true);
        if ($claims['sub'] !== (int)$user->user['uid']) {
            throw new \UnexpectedValueException('Preview issuer mismatch', 1793200804);
        }
        $this->loadPermissionUser($claims);
        return $this->sign($claims);
    }

    public function loadPermissionUser(array $claims): BackendUserAuthentication
    {
        $user = GeneralUtility::makeInstance(PreviewPermissionUser::class);
        $user->setBeUserByUid($claims['sub']);
        if (!$user->user) {
            throw new \UnexpectedValueException('Preview issuer unavailable', 1793200805);
        }
        $user->user['workspace_id'] = $claims['workspace'];
        $user->fetchGroupData();
        $this->assertPermissions($claims, $user);
        return $user;
    }

    public function assertPermissions(array $claims, BackendUserAuthentication $user): void
    {
        if (!$user->user || !$user->checkWorkspace($claims['workspace'])) {
            throw new \UnexpectedValueException('Preview workspace denied', 1793200806);
        }
        foreach ($claims['groups'] as $groupId) {
            $group = $this->connectionPool->getConnectionForTable('fe_groups')->select(['*'], 'fe_groups', ['uid' => $groupId, 'deleted' => 0, 'hidden' => 0])->fetchAssociative();
            if (!$group || (!$user->isAdmin() && (!$user->check('tables_select', 'fe_groups') || !$user->isInWebMount((int)$group['pid'])))) {
                throw new \UnexpectedValueException('Preview group denied', 1793200807);
            }
        }
    }

    public function assertPageAccess(array $claims, BackendUserAuthentication $user, int $pageId): void
    {
        $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
        $page = $rootline[0] ?? [];
        $rootlineIds = array_map('intval', array_column($rootline, 'uid'));
        if (!$page || (!$user->isAdmin() && (!$user->isInWebMount($pageId) || !$user->doesUserHaveAccess($page, Permission::PAGE_SHOW)))
            || (!in_array(0, $claims['mounts'], true) && !array_intersect($claims['mounts'], $rootlineIds))
        ) {
            throw new \UnexpectedValueException('Preview page denied', 1793200808);
        }
    }

    public function allowsUri(array $claims, UriInterface $uri): bool
    {
        if (!in_array($uri->getScheme(), ['https', 'http'], true) || $uri->getUserInfo() !== '') {
            return false;
        }
        $path = UriResolver::removeDotSegments(rawurldecode($uri->getPath()));
        foreach ($claims['scope'] as $languages) {
            foreach ($languages as $base) {
                $base = new Uri($base);
                $basePath = rtrim(rawurldecode($base->getPath()), '/');
                if ($base->getScheme() === $uri->getScheme() && $base->getAuthority() === $uri->getAuthority()
                    && ($path === $basePath || str_starts_with($path, $basePath . '/'))
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    public function withToken(UriInterface $uri, string $token): UriInterface
    {
        parse_str($uri->getQuery(), $query);
        $query['_ps'] = $token;
        unset($query['ADMCMD_prev'], $query['ADMCMD_simTime'], $query['ADMCMD_simUser']);
        return $uri->withQuery(HttpUtility::buildQueryString($query));
    }

    private function sign(array $claims): string
    {
        $claims['jti'] = bin2hex(random_bytes(16));
        $claims['iat'] = time();
        $claims['exp'] = $claims['iat'] + self::LIFETIME;
        return self::encodeHashSignedJwt($claims, self::createSigningKeyFromEncryptionKey());
    }

    private function siteOverlapsMounts(Site $site, array $mounts): bool
    {
        if (in_array(0, $mounts, true)) {
            return true;
        }
        $rootId = $site->getRootPageId();
        $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $rootId)->get();
        if (array_intersect($mounts, array_map('intval', array_column($rootline, 'uid')))) {
            return true;
        }
        foreach ($mounts as $mount) {
            if (in_array($rootId, array_map('intval', array_column(GeneralUtility::makeInstance(RootlineUtility::class, $mount)->get(), 'uid')), true)) {
                return true;
            }
        }
        return false;
    }
}
