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

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @internal Reserved system identity provisioning for CLI authentication, system writes and TYPO3 setup
 */
#[Autoconfigure(public: true)]
final readonly class CommandLineUserCreation
{
    public const CLI_USERNAME = '_cli_';

    public function __construct(
        private ConnectionPool $connectionPool,
        private PasswordHashFactory $passwordHashFactory,
        private LockFactory $lockFactory,
    ) {}

    /**
     * Create a record in the DB table be_users called "_cli_" with no other information,
     * when it does not exist already
     */
    public function ensureCliUserExists(): bool
    {
        $lock = $this->lockFactory->createLocker('core-cli-user-provisioning');
        if (!$lock->acquire()) {
            throw new \RuntimeException('Cannot acquire the system identity provisioning lock.', 1792320001);
        }
        try {
            // Check the reserved name and insert while holding the same installation lock.
            if ($this->cliUserExists()) {
                return false;
            }
            $userFields = [
                'username' => self::CLI_USERNAME,
                'password' => $this->generateHashedPassword(),
                'admin'    => 1,
                'tstamp'   => $GLOBALS['EXEC_TIME'] ?? time(),
                'crdate'   => $GLOBALS['EXEC_TIME'] ?? time(),
            ];

            $databaseConnection = $this->connectionPool->getConnectionForTable('be_users');
            $databaseConnection->insert('be_users', $userFields);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Check the reserved username without enable-column filtering. Even a deleted or
     * unavailable identity must not be replaced with a new administrator.
     */
    private function cliUserExists(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter(self::CLI_USERNAME)))
            ->executeQuery()
            ->fetchOne();
        return (bool)$count;
    }

    /**
     * This function returns a salted hashed key.
     */
    private function generateHashedPassword(): string
    {
        $cryptoService = GeneralUtility::makeInstance(Random::class);
        $password = $cryptoService->generateRandomBytes(20);
        return $this->passwordHashFactory
            ->getDefaultHashInstance('BE')
            ->getHashedPassword($password);
    }
}
