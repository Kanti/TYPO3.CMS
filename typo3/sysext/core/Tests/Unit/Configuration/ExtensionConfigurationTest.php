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
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Package\Package;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\TypoScript\Tokenizer\LosslessTokenizer;
use TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class ExtensionConfigurationTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;
    private PackageManager $originalPackageManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPackageManager = new \ReflectionProperty(ExtensionManagementUtility::class, 'packageManager')->getValue();
    }

    protected function tearDown(): void
    {
        ExtensionManagementUtility::setPackageManager($this->originalPackageManager);
        parent::tearDown();
    }

    #[Test]
    public function getReturnsPathAddedDuringSynchronization(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['someExtension'] = ['existing' => true];
        $subject = $this->createPartialMock(ExtensionConfiguration::class, ['synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions']);
        $subject->expects($this->once())->method('synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions')->with(true)
            ->willReturnCallback(static function (): void {
                $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['someExtension'] = ['enabled' => true];
            });

        self::assertTrue($subject->get('someExtension', 'enabled'));
    }

    #[Test]
    public function getThrowsIfPathRemainsMissingAfterSynchronization(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['someExtension'] = ['existing' => true];
        $subject = $this->createPartialMock(ExtensionConfiguration::class, ['synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions']);
        $subject->expects($this->once())->method('synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions')->with(true);

        $this->expectException(ExtensionConfigurationPathDoesNotExistException::class);
        $this->expectExceptionCode(1509977699);
        $subject->get('someExtension', 'enabled');
    }

    #[Test]
    public function getWithEmptyPathReturnsEntireExtensionConfiguration(): void
    {
        $extConf = [
            'aFeature' => 'iAmEnabled',
            'aFlagCategory' => [
                'someFlag' => 'foo',
            ],
        ];
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['someExtension'] = $extConf;
        self::assertSame(new ExtensionConfiguration()->get('someExtension'), $extConf);
    }

    #[Test]
    public function getWithPathReturnsGivenValue(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['someExtension'] = [
            'aFeature' => 'iAmEnabled',
            'aFlagCategory' => [
                'someFlag' => 'foo',
            ],
        ];
        self::assertSame(new ExtensionConfiguration()->get('someExtension', 'aFeature'), 'iAmEnabled');
    }

    #[Test]
    public function getWithPathReturnsGivenPathSegment(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['someExtension'] = [
            'aFeature' => 'iAmEnabled',
            'aFlagCategory' => [
                'someFlag' => 'foo',
            ],
        ];
        self::assertSame(new ExtensionConfiguration()->get('someExtension', 'aFlagCategory'), ['someFlag' => 'foo']);
    }

    #[Test]
    public function setThrowsExceptionWithEmptyExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1509715852);
        new ExtensionConfiguration()->set('');
    }

    #[Test]
    public function setRemovesFullExtensionConfiguration(): void
    {
        $configurationManagerMock = $this->createMock(ConfigurationManager::class);
        GeneralUtility::addInstance(ConfigurationManager::class, $configurationManagerMock);
        $configurationManagerMock->expects($this->once())->method('removeLocalConfigurationKeysByPath')->with(['EXTENSIONS/foo']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['foo'] = [
            'bar' => 'baz',
        ];
        new ExtensionConfiguration()->set('foo');
        self::assertFalse(isset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['foo']));
    }

    #[Test]
    public function setWritesFullExtensionConfig(): void
    {
        $value = ['bar' => 'baz'];
        $configurationManagerMock = $this->createMock(ConfigurationManager::class);
        GeneralUtility::addInstance(ConfigurationManager::class, $configurationManagerMock);
        $configurationManagerMock->expects($this->once())->method('setLocalConfigurationValueByPath')->with('EXTENSIONS/foo', $value);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['foo'] = [
            'bar' => 'fizz',
            'bee' => 'boo',
        ];
        new ExtensionConfiguration()->set('foo', $value);
        self::assertSame($value, $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['foo']);
    }

    public static function synchronizationDataProvider(): iterable
    {
        yield 'all extensions, new default' => [true, false, false];
        yield 'single extension, new default' => [false, false, false];
        yield 'all extensions, defaults already stored' => [true, true, false];
        yield 'single extension, defaults already stored' => [false, true, false];
        yield 'installation without settings file' => [true, false, true];
    }

    #[Test]
    #[DataProvider('synchronizationDataProvider')]
    public function synchronizationDoesNotPersistRuntimeOverrides(bool $allExtensions, bool $defaultsAlreadyStored, bool $missingSettingsFile): void
    {
        $package = self::createStub(Package::class);
        $package->method('getPackageKey')->willReturn('foo');
        $package->method('getPackagePath')->willReturn(__DIR__ . '/Fixtures/ExtensionConfiguration/');
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn(['foo' => $package]);
        $packageManager->expects($this->atLeastOnce())->method('getPackage')->with('foo')->willReturn($package);
        $packageManager->method('extractPackageKeyFromPackagePath')->willReturn('foo');
        GeneralUtility::setSingletonInstance(PackageManager::class, $packageManager);
        ExtensionManagementUtility::setPackageManager($packageManager);
        GeneralUtility::addInstance(TypoScriptStringFactory::class, new TypoScriptStringFactory(
            self::createStub(ContainerInterface::class),
            new LosslessTokenizer(),
        ));

        $configurationManager = $this->createMock(ConfigurationManager::class);
        GeneralUtility::addInstance(ConfigurationManager::class, $configurationManager);
        $stored = ['nested' => ['existing' => 'stored']];
        if ($defaultsAlreadyStored) {
            $stored['nested']['new'] = 'default';
        }
        $configurationManager->expects($missingSettingsFile ? $this->never() : $this->once())->method('getLocalConfiguration')->willReturn([
            'EXTENSIONS' => ['foo' => $stored],
        ]);
        $configurationManager->method('getSystemConfigurationFileLocation')->willReturn(__DIR__ . '/Fixtures/ExtensionConfiguration/settings.php');
        $expected = ['nested' => ['existing' => 'stored', 'new' => 'default']];
        $configurationManager->expects($defaultsAlreadyStored || $missingSettingsFile ? $this->never() : $this->once())->method('setLocalConfigurationValueByPath')->with(
            $allExtensions ? 'EXTENSIONS' : 'EXTENSIONS/foo',
            $allExtensions ? ['foo' => $expected] : $expected,
        );
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'] = [
            'foo' => ['nested' => ['existing' => 'additional', 'runtimeOnly' => 'additional']],
        ];
        $subject = new ExtensionConfiguration();
        if ($allExtensions) {
            $subject->synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions($missingSettingsFile);
        } else {
            $subject->synchronizeExtConfTemplateWithLocalConfiguration('foo');
        }
        self::assertSame([
            'existing' => 'additional',
            'new' => 'default',
            'runtimeOnly' => 'additional',
        ], $subject->get('foo', 'nested'));
    }
}
