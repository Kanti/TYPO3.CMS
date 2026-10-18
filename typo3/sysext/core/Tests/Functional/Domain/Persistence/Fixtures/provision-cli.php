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

use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserCreation;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Testbase;

require $argv[1];
putenv('TYPO3_PATH_ROOT=' . $argv[2]);
putenv('TYPO3_PATH_APP=' . $argv[2]);
(new Testbase())->setUpBasicTypo3Bootstrap($argv[2]);
$barrier = static function () use ($argv): void {
    file_put_contents($argv[3] . '/ready-' . $argv[4], 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($argv[3] . '/go')) {
        if (microtime(true) > $deadline) {
            throw new \RuntimeException('Provisioning barrier timed out.', 1792320004);
        }
        usleep(1000);
    }
};
if (($argv[5] ?? 'provision') === 'authenticate') {
    $user = new class ($barrier) extends CommandLineUserAuthentication {
        private bool $firstLookup = true;

        public function __construct(private readonly \Closure $barrier)
        {
            parent::__construct();
        }

        public function setBeUserByName($name)
        {
            parent::setBeUserByName($name);
            if ($this->firstLookup) {
                $this->firstLookup = false;
                ($this->barrier)();
            }
        }
    };
    $user->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(CommandLineUserAuthentication::class));
    $GLOBALS['BE_USER'] = $user;
    $user->authenticate();
    $result = ['uid' => (int)$user->getUserId()];
} else {
    $barrier();
    $result = ['created' => GeneralUtility::makeInstance(CommandLineUserCreation::class)->ensureCliUserExists()];
}
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
