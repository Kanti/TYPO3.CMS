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

namespace TYPO3\CMS\Core\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class ConfigurationPersistenceTest extends UnitTestCase
{
    public static function configurationWritesDataProvider(): iterable
    {
        yield 'single value' => ['single', 'SYS/sitename', 'changed'];
        yield 'multiple values' => ['multiple', 'SYS/sitename', 'changed'];
        yield 'configuration merge' => ['merge', 'SYS/sitename', 'changed'];
        yield 'remove value' => ['remove', 'SYS/sitename', null];
        yield 'explicitly persist effective value' => ['single', 'MAIL/transport_smtp_password', 'runtime-secret'];
        yield 'boolean false' => ['single', 'SYS/displayErrors', false];
        yield 'integer zero' => ['single', 'SYS/displayErrors', 0];
        yield 'string zero' => ['single', 'SYS/displayErrors', '0'];
        yield 'empty string' => ['single', 'SYS/displayErrors', ''];
        yield 'null value' => ['single', 'SYS/displayErrors', null];
        yield 'delete map key' => ['single', 'HTTP/headers', ['a' => 'stored']];
        yield 'clear list' => ['single', 'SYS/systemMaintainers', []];
    }

    #[Test]
    #[DataProvider('configurationWritesDataProvider')]
    public function explicitWritesPreserveStoredConfigurationWithoutCopyingAdditionalOverrides(string $operation, string $path, mixed $value): void
    {
        $directory = Environment::getVarPath() . '/tests/' . StringUtility::getUniqueId('configurationPersistence_');
        GeneralUtility::mkdir_deep($directory);
        $this->testFilesToDelete[] = $directory;
        $stored = [
            'BE' => ['debug' => false],
            'SYS' => ['sitename' => 'stored', 'displayErrors' => 0, 'systemMaintainers' => [1, 2]],
            'MAIL' => ['transport_smtp_password' => 'stored-secret'],
            'HTTP' => ['headers' => ['a' => 'stored', 'b' => 'stored']],
            'DB' => ['Connections' => ['Default' => ['host' => 'stored-host']]],
            'GFX' => ['processor_enabled' => false],
            'FE' => ['debug' => false],
            'LANG' => ['availableLocales' => ['de']],
            'EXTCONF' => ['example' => ['stored' => true]],
            'EXTENSIONS' => ['example' => ['stored' => true]],
        ];
        file_put_contents($directory . '/settings.php', '<?php return ' . var_export($stored, true) . ';');
        file_put_contents($directory . '/additional.php', <<<'PHP'
<?php
$GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = true;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = 'additional';
unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors']);
$GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] = [3];
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password'] = 'runtime-secret';
$GLOBALS['TYPO3_CONF_VARS']['HTTP']['headers'] = ['runtime' => 'only'];
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'] = 'runtime-host';
$GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = true;
$GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;
$GLOBALS['TYPO3_CONF_VARS']['LANG']['availableLocales'] = ['fr'];
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['example']['runtime'] = true;
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['example']['runtime'] = true;
PHP);
        $subject = $this->getMockBuilder(ConfigurationManager::class)
            ->onlyMethods(['getDefaultConfiguration', 'getSystemConfigurationFileLocation', 'getAdditionalConfigurationFileLocation'])->getMock();
        $subject->method('getDefaultConfiguration')->willReturn($stored);
        $subject->expects($this->atLeastOnce())->method('getSystemConfigurationFileLocation')->willReturn($directory . '/settings.php');
        $subject->method('getAdditionalConfigurationFileLocation')->willReturn($directory . '/additional.php');
        $subject->exportConfiguration();
        self::assertSame('runtime-secret', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password']);
        $expected = $stored;
        if ($operation === 'remove') {
            unset($expected['SYS']['sitename']);
            $subject->removeLocalConfigurationKeysByPath([$path]);
        } elseif ($operation === 'merge') {
            $expected['SYS']['sitename'] = $value;
            $subject->updateLocalConfiguration(['SYS' => ['sitename' => $value]]);
        } else {
            [$section, $key] = explode('/', $path, 2);
            $expected[$section][$key] = $value;
            if ($operation === 'multiple') {
                $subject->setLocalConfigurationValuesByPathValuePairs([$path => $value]);
            } else {
                $subject->setLocalConfigurationValueByPath($path, $value);
            }
        }
        self::assertEquals($expected, $subject->getLocalConfiguration());
        if ($path === 'SYS/displayErrors') {
            self::assertSame($value, $subject->getLocalConfiguration()['SYS']['displayErrors']);
        }
        $subject->exportConfiguration();
        self::assertSame('additional', $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']);
        self::assertSame('runtime-secret', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password']);
    }
}
