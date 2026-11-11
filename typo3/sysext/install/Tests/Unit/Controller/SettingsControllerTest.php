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

namespace TYPO3\CMS\Install\Tests\Unit\Controller;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Controller\SettingsController;
use TYPO3\CMS\Install\Service\LateBootService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class SettingsControllerTest extends UnitTestCase
{
    public static function extensionConfigurationDataProvider(): iterable
    {
        yield 'unchanged override' => [
            ['nested.existing' => 'additional', 'other' => 'stored'],
            ['nested' => ['existing' => 'stored'], 'other' => 'stored'],
        ];
        yield 'explicitly changed override' => [
            ['nested.existing' => 'changed', 'other' => 'stored'],
            ['nested' => ['existing' => 'changed'], 'other' => 'stored'],
        ];
        yield 'changing another field preserves stored override' => [
            ['nested.existing' => 'additional', 'other' => 'changed'],
            ['nested' => ['existing' => 'stored'], 'other' => 'changed'],
        ];
        yield 'boolean false override matches checkbox zero' => [
            ['nested.existing' => '0'],
            ['nested' => ['existing' => 'stored'], 'other' => 'stored'],
            false,
        ];
        yield 'boolean true override matches checkbox one' => [
            ['nested.existing' => '1'],
            ['nested' => ['existing' => 'stored'], 'other' => 'stored'],
            true,
        ];
        yield 'integer zero override matches form string' => [
            ['nested.existing' => '0'],
            ['nested' => ['existing' => 'stored'], 'other' => 'stored'],
            0,
        ];
        yield 'unchanged runtime-only field' => [
            ['nested.runtimeOnly' => 'additional'],
            ['nested' => ['existing' => 'stored'], 'other' => 'stored'],
        ];
        yield 'explicitly changed runtime-only field' => [
            ['nested.runtimeOnly' => 'changed'],
            ['nested' => ['existing' => 'stored', 'runtimeOnly' => 'changed'], 'other' => 'stored'],
        ];
    }

    #[Test]
    #[DataProvider('extensionConfigurationDataProvider')]
    public function extensionConfigurationWritesOnlyChangedValues(array $submitted, array $expected, mixed $runtimeValue = 'additional'): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['foo'] = [
            'nested' => ['existing' => $runtimeValue, 'runtimeOnly' => 'additional'],
            'other' => 'stored',
        ];
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('canWriteConfiguration')->willReturn(true);
        $configurationManager->method('getLocalConfiguration')->willReturn([
            'EXTENSIONS' => ['foo' => ['nested' => ['existing' => 'stored'], 'other' => 'stored']],
        ]);
        $configurationManager->expects($this->once())->method('setLocalConfigurationValueByPath')->with('EXTENSIONS/foo', $expected);
        GeneralUtility::addInstance(ConfigurationManager::class, $configurationManager);
        $subject = new \ReflectionClass(SettingsController::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(SettingsController::class, 'configurationManager')->setValue($subject, $configurationManager);
        $request = new ServerRequest()->withParsedBody([
            'install' => ['extensionKey' => 'foo', 'extensionConfiguration' => $submitted],
        ]);
        $response = $subject->extensionConfigurationWriteAction($request);
        self::assertSame(200, $response->getStatusCode());
    }

    public static function featuresDataProvider(): iterable
    {
        yield 'unchanged enabled override' => [false, true, true, false];
        yield 'unchanged disabled override' => [true, false, false, false];
        yield 'explicitly disabled override' => [false, true, false, true];
        yield 'explicitly enabled override' => [true, false, true, true];
        yield 'integer runtime flag matches checkbox' => [false, 1, true, false];
        yield 'string runtime flag matches checkbox' => [true, '0', false, false];
    }

    #[Test]
    #[DataProvider('featuresDataProvider')]
    public function featuresSavePersistsOnlyExplicitChanges(bool $stored, bool|int|string $effective, bool $submitted, bool $shouldWrite): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'] = ['overridden' => $effective, 'other' => false];
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('canWriteConfiguration')->willReturn(true);
        $configurationManager->method('getDefaultConfigurationDescriptionFileLocation')->willReturn('configuration-description.yaml');
        $configurationManager->method('getConfigurationValueByPath')->willReturnMap([['SYS/features/overridden', $stored], ['SYS/features/other', false]]);
        if ($shouldWrite) {
            $configurationManager->expects($this->once())->method('setLocalConfigurationValuesByPathValuePairs')
                ->with(['SYS/features/overridden' => $submitted])->willReturn(true);
            $configurationManager->expects($this->once())->method('exportConfiguration');
        } else {
            $configurationManager->expects($this->never())->method('setLocalConfigurationValuesByPathValuePairs');
            $configurationManager->expects($this->never())->method('exportConfiguration');
        }
        $loader = $this->createMock(YamlFileLoader::class);
        $loader->expects($this->once())->method('load')->willReturn([
            'SYS' => ['items' => ['features' => ['items' => [
                'overridden' => ['description' => 'An overridden feature'],
                'other' => ['description' => 'Another feature'],
            ]]]],
        ]);
        GeneralUtility::addInstance(YamlFileLoader::class, $loader);
        $subject = new \ReflectionClass(SettingsController::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(SettingsController::class, 'configurationManager')->setValue($subject, $configurationManager);
        $request = new ServerRequest()->withParsedBody(['install' => ['values' => $submitted ? ['overridden' => '1'] : []]]);
        self::assertSame(200, $subject->featuresSaveAction($request)->getStatusCode());
    }

    public static function systemMaintainerNoWriteDataProvider(): iterable
    {
        yield 'unchanged runtime list, normalized membership' => [true, ['1', '2']];
        yield 'write-protected configuration' => [false, ['3']];
    }

    #[Test]
    #[DataProvider('systemMaintainerNoWriteDataProvider')]
    public function systemMaintainersSaveDoesNotWriteUnchangedOrProtectedConfiguration(bool $writable, array $users): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] = [2, 1];
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('canWriteConfiguration')->willReturn($writable);
        $configurationManager->expects($this->never())->method('setLocalConfigurationValuesByPathValuePairs');
        $lateBootService = $this->createMock(LateBootService::class);
        $lateBootService->expects($this->never())->method('getContainer');
        $subject = new \ReflectionClass(SettingsController::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(SettingsController::class, 'configurationManager')->setValue($subject, $configurationManager);
        new \ReflectionProperty(SettingsController::class, 'lateBootService')->setValue($subject, $lateBootService);
        $request = new ServerRequest()->withParsedBody(['install' => ['users' => $users]]);
        self::assertSame(200, $subject->systemMaintainerWriteAction($request)->getStatusCode());
    }

    #[Test]
    public function systemMaintainersSavePersistsExplicitlyChangedList(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] = [1, 2];
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('canWriteConfiguration')->willReturn(true);
        $configurationManager->expects($this->once())->method('setLocalConfigurationValuesByPathValuePairs')
            ->with(['SYS/systemMaintainers' => [1, 3]]);
        $result = self::createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([['uid' => '1'], ['uid' => '3']]);
        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('createNamedParameter')->willReturn(':value');
        $queryBuilder->method('getRestrictions')->willReturn(self::createStub(QueryRestrictionContainerInterface::class));
        $queryBuilder->method('expr')->willReturn(new ExpressionBuilder(self::createStub(Connection::class), self::createStub(ContainerInterface::class)));
        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $container = self::createStub(ContainerInterface::class);
        $container->method('get')->willReturn($connectionPool);
        $lateBootService = self::createStub(LateBootService::class);
        $lateBootService->method('getContainer')->willReturn($container);
        $subject = new \ReflectionClass(SettingsController::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(SettingsController::class, 'configurationManager')->setValue($subject, $configurationManager);
        new \ReflectionProperty(SettingsController::class, 'lateBootService')->setValue($subject, $lateBootService);
        $request = new ServerRequest()->withParsedBody(['install' => ['users' => ['1', '3']]]);
        self::assertSame(200, $subject->systemMaintainerWriteAction($request)->getStatusCode());
    }

}
