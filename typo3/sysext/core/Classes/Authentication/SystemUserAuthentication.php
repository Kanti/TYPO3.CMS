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

namespace TYPO3\CMS\Core\Authentication;

use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sessionless server-side identity using the installed CLI user's actual permissions.
 * @internal Used by trusted Core write services. Does not authorize HTTP requests.
 */
final class SystemUserAuthentication extends BackendUserAuthentication
{
    public function initialize(): void
    {
        if (!in_array((int)$GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'], [0, 2], true)) {
            throw new \RuntimeException('System writes are unavailable in maintenance mode.', 1792310001);
        }
        $this->dontSetCookie = true;
        $this->setBeUserByName(CommandLineUserCreation::CLI_USERNAME);
        if (!$this->getUserId()) {
            GeneralUtility::makeInstance(CommandLineUserCreation::class)->ensureCliUserExists();
            $this->setBeUserByName(CommandLineUserCreation::CLI_USERNAME);
        }
        if (!$this->getUserId()) {
            throw new \RuntimeException('The reserved _cli_ identity exists but is unavailable for system writes.', 1792310002);
        }
        $this->unpack_uc();
    }

    protected function userConstraints(): QueryRestrictionContainerInterface
    {
        // Frontend preview time must not enable an expired system identity.
        $constraints = parent::userConstraints();
        $constraints->removeByType(StartTimeRestriction::class);
        $constraints->removeByType(EndTimeRestriction::class);
        $constraints->add(new StartTimeRestriction((int)$GLOBALS['EXEC_TIME']));
        $constraints->add(new EndTimeRestriction((int)$GLOBALS['EXEC_TIME']));
        return $constraints;
    }

    protected function workspaceInit(): void
    {
        // System writes default to Live without persisting a user's workspace preference.
        if (!$this->setTemporaryWorkspace(0)) {
            throw new \RuntimeException('The system user cannot access the Live workspace.', 1792310003);
        }
        $this->initializeDbMountpointsInWorkspace();
    }

    public function getOriginalUserIdWhenInSwitchUserMode(): ?int
    {
        return null;
    }
}
