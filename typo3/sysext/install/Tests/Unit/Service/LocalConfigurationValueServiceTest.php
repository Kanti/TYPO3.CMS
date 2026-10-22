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

namespace TYPO3\CMS\Install\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Service\LocalConfigurationValueService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class LocalConfigurationValueServiceTest extends UnitTestCase
{
    public static function configurationDataProvider(): iterable
    {
        yield 'unchanged string' => ['text', 'stored', 'additional', 'stored', null];
        yield 'explicit string change' => ['text', 'stored', 'additional', 'changed', 'changed'];
        yield 'explicitly copy effective value' => ['text', 'stored', 'additional', 'additional', 'additional'];
        yield 'false checkbox' => ['bool', false, true, '0', null];
        yield 'true checkbox' => ['bool', true, false, '1', null];
        yield 'enable checkbox' => ['bool', false, true, '1', true];
        yield 'integer zero' => ['int', 0, 42, '0', null];
        yield 'integer change' => ['int', 0, 42, '1', 1];
        yield 'string zero remains a string' => ['text', 'stored', 'additional', '0', '0'];
        yield 'empty string' => ['text', 'stored', 'additional', '', ''];
        yield 'multiline normalizes CRLF' => ['multiline', "line1\nline2", 'additional', "line1\r\nline2", null];
        yield 'unchanged map with different key order' => ['map', ['a' => '1', 'b' => '2'], ['a' => 'additional'], ['b' => '2', 'a' => '1'], null];
        yield 'delete map key' => ['map', ['a' => '1', 'b' => '2'], ['a' => 'additional', 'runtime' => 'only'], ['a' => '1'], ['a' => '1']];
        yield 'clear map' => ['map', ['a' => '1'], ['a' => 'additional'], [], []];
        yield 'unchanged list' => ['element-list', ['a', 'b'], ['additional'], ['a', 'b'], null];
        yield 'remove list item' => ['element-list', ['a', 'b'], ['additional'], ['a'], ['a']];
        yield 'clear list' => ['element-list', ['a'], ['additional'], [], []];
        yield 'comma separated list' => ['array', ['a', 'b'], ['additional'], 'a,b', null];
        yield 'changed comma separated list' => ['array', ['a', 'b'], ['additional'], 'a,c', ['a', 'c']];
    }

    #[Test]
    #[DataProvider('configurationDataProvider')]
    public function savingGlobalSettingsOnlyWritesExplicitChanges(string $type, mixed $stored, mixed $effective, mixed $submitted, mixed $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['testing']['value'] = $effective;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password'] = 'runtime-only';
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->expects($this->once())->method('getConfigurationValueByPath')->with('SYS/testing/value')->willReturn($stored);
        if ($expected === null) {
            $configurationManager->expects($this->never())->method('setLocalConfigurationValuesByPathValuePairs');
        } else {
            $configurationManager->expects($this->once())->method('setLocalConfigurationValuesByPathValuePairs')
                ->with(['SYS/testing/value' => $expected]);
        }
        GeneralUtility::addInstance(ConfigurationManager::class, $configurationManager);
        $subject = $this->getMockBuilder(LocalConfigurationValueService::class)->onlyMethods(['getDefaultConfigArrayComments'])->getMock();
        $subject->expects($this->once())->method('getDefaultConfigArrayComments')->willReturn([
            'SYS' => ['items' => ['testing' => ['type' => 'container', 'items' => ['value' => ['type' => $type]]]]],
        ]);
        $messages = $subject->updateLocalConfigurationValues(['SYS/testing/value' => $submitted]);
        self::assertCount($expected === null ? 0 : 1, $messages);
    }

    #[Test]
    public function globalSettingsFormDisplaysStoredValuesAndMarksRuntimeOverrides(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = 'additional';
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->expects($this->once())->method('getMergedLocalConfiguration')->willReturn(['SYS' => ['sitename' => 'stored']]);
        GeneralUtility::addInstance(ConfigurationManager::class, $configurationManager);
        $subject = $this->getMockBuilder(LocalConfigurationValueService::class)->onlyMethods(['getDefaultConfigArrayComments'])->getMock();
        $subject->expects($this->once())->method('getDefaultConfigArrayComments')->willReturn([
            'SYS' => ['items' => ['sitename' => ['type' => 'text']]],
        ]);
        $data = $subject->getCurrentConfigurationData();
        self::assertSame('stored', $data['SYS']['items'][0]['value']);
        self::assertTrue($data['SYS']['items'][0]['differentValueInCurrentConfiguration']);
    }
}
