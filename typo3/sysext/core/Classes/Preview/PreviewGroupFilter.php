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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Decides which frontend user groups an editor may simulate in a preview:
 * the groups the editor may read, and the groups the access restrictions of
 * the previewed page name, so an editor can preview a protected page without
 * read access to its group.
 *
 * @internal
 */
final readonly class PreviewGroupFilter
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int> $groups
     * @param array<int|string, array<string, mixed>> $rootLine the previewed page first, as RootlineUtility returns it
     * @return list<int>
     */
    public function filter(BackendUserAuthentication $user, array $groups, array $rootLine): array
    {
        if ($groups === []) {
            return [];
        }
        $allowedGroups = [...array_keys($this->getReadableGroups($user)), ...$this->getGroupsOfPage($rootLine)];
        return array_values(array_filter(
            array_unique($groups),
            static fn(int $group): bool => $group > 0 && in_array($group, $allowedGroups, true)
        ));
    }

    /**
     * Frontend user groups the editor may read and therefore simulate freely.
     *
     * @return array<int, string> titles by uid
     */
    public function getReadableGroups(BackendUserAuthentication $user): array
    {
        if (!$user->isAdmin() && !$user->check('tables_select', 'fe_groups')) {
            return [];
        }
        $rows = $this->connectionPool->getQueryBuilderForTable('fe_groups')
            ->select('uid', 'pid', 'title')
            ->from('fe_groups')
            ->orderBy('title')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        $readablePages = [];
        $groups = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pid'];
            $readablePages[$pid] ??= $this->isPageReadable($user, $pid);
            if ($readablePages[$pid]) {
                $groups[(int)$row['uid']] = (string)$row['title'];
            }
        }
        return $groups;
    }

    /**
     * The groups the access restrictions of the page or of a page above it with
     * "extendToSubpages" name, as PreviewUriBuilder simulates them: for "show at any
     * login" (-2) the first active group.
     *
     * @param array<int|string, array<string, mixed>> $rootLine the previewed page first
     * @return list<int>
     */
    private function getGroupsOfPage(array $rootLine): array
    {
        $page = reset($rootLine);
        if (!is_array($page)) {
            return [];
        }
        $groupList = (string)($page['fe_group'] ?? '');
        if (!(bool)($page['extendToSubpages'] ?? false)) {
            foreach (array_slice($rootLine, 1) as $parent) {
                if ((int)($parent['uid'] ?? 0) === 0) {
                    continue;
                }
                if ((bool)($parent['extendToSubpages'] ?? false)) {
                    $groupList = (string)($parent['fe_group'] ?? '');
                    break;
                }
            }
        }
        if ((int)$groupList === -2) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_groups');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                ->add(GeneralUtility::makeInstance(HiddenRestriction::class));
            $firstActiveGroup = (int)$queryBuilder->select('uid')->from('fe_groups')->executeQuery()->fetchOne();
            return $firstActiveGroup > 0 ? [$firstActiveGroup] : [];
        }
        return array_values(array_filter(GeneralUtility::intExplode(',', $groupList, true), static fn(int $group): bool => $group > 0));
    }

    private function isPageReadable(BackendUserAuthentication $user, int $pageId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($pageId <= 0) {
            return false;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $page = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($page)) {
            return false;
        }
        return $user->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }
}
