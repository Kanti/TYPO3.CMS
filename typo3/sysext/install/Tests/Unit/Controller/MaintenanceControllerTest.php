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

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguagePackService;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\PasswordPolicy\PasswordPolicyValidator;
use TYPO3\CMS\Install\Controller\MaintenanceController;
use TYPO3\CMS\Install\Service\LateBootService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class MaintenanceControllerTest extends UnitTestCase
{
    public static function languageChangesDataProvider(): iterable
    {
        yield 'activate without copying runtime-only locales' => ['activate', ['de'], ['de', 'fr'], 'it', ['de', 'it']];
        yield 'activate without removing locales hidden by additional' => ['activate', ['de', 'fr'], ['de'], 'it', ['de', 'fr', 'it']];
        yield 'activate with dependency' => ['activate', ['de'], ['de'], 'fr_CA', ['de', 'fr', 'fr_CA']];
        yield 'activate with runtime-only dependency' => ['activate', ['de'], ['de', 'fr'], 'fr_CA', ['de', 'fr', 'fr_CA']];
        yield 'deactivate without copying runtime-only locale' => ['deactivate', ['de', 'it'], ['de', 'fr', 'it'], 'it', ['de']];
        yield 'deactivate without removing hidden locale' => ['deactivate', ['de', 'fr', 'it'], ['de', 'it'], 'it', ['de', 'fr']];
        yield 'deactivate runtime-only locale does not write settings' => ['deactivate', ['de'], ['de', 'fr'], 'fr', null];
    }

    #[Test]
    #[DataProvider('languageChangesDataProvider')]
    public function changingLanguagePacksDoesNotPersistOtherRuntimeOverrides(string $action, array $stored, array $effective, string $iso, ?array $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LANG']['availableLocales'] = $effective;
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('canWriteConfiguration')->willReturn(true);
        $configurationManager->method('getLocalConfiguration')->willReturn(['LANG' => ['availableLocales' => $stored, 'other' => 'stored']]);
        if ($expected === null) {
            $configurationManager->expects($this->never())->method('setLocalConfigurationValueByPath');
        } else {
            $configurationManager->expects($this->once())->method('setLocalConfigurationValueByPath')->with('LANG/availableLocales', $expected);
        }
        $languagePackService = self::createStub(LanguagePackService::class);
        $languagePackService->method('getAvailableLanguages')->willReturn(['de' => 'German', 'fr' => 'French', 'fr_CA' => 'French (Canada)', 'it' => 'Italian']);
        $languagePackService->method('getActiveLanguages')->willReturn($effective);
        $container = self::createStub(ContainerInterface::class);
        $container->method('get')->willReturn($languagePackService);
        $lateBootService = self::createStub(LateBootService::class);
        $lateBootService->method('getContainer')->willReturn($container);
        $locales = self::createStub(Locales::class);
        $locales->method('getLocaleDependencies')->willReturnMap([['de', []], ['fr', []], ['fr_CA', ['fr']], ['it', []]]);
        $subject = new \ReflectionClass(MaintenanceController::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(MaintenanceController::class, 'configurationManager')->setValue($subject, $configurationManager);
        new \ReflectionProperty(MaintenanceController::class, 'lateBootService')->setValue($subject, $lateBootService);
        new \ReflectionProperty(MaintenanceController::class, 'locales')->setValue($subject, $locales);
        $request = new ServerRequest()->withParsedBody(['install' => ['iso' => $iso]]);
        $response = $action === 'activate'
            ? $subject->languagePacksActivateLanguageAction($request)
            : $subject->languagePacksDeactivateLanguageAction($request);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function creatingMaintainerDoesNotCopyExistingRuntimeMaintainers(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] = [99];
        $GLOBALS['EXEC_TIME'] = 1234567890;
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('getLocalConfiguration')->willReturn(['SYS' => ['systemMaintainers' => [1]]]);
        $configurationManager->expects($this->once())->method('setLocalConfigurationValuesByPathValuePairs')
            ->with(['SYS/systemMaintainers' => [1, 7]]);
        $connection = $this->createMock(Connection::class);
        $connection->method('count')->willReturn(0);
        $connection->expects($this->once())->method('insert')->with('be_users', self::isArray())->willReturn(1);
        $connection->method('lastInsertId')->willReturn('7');
        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        $container = self::createStub(ContainerInterface::class);
        $container->method('get')->willReturn($connectionPool);
        $lateBootService = self::createStub(LateBootService::class);
        $lateBootService->method('getContainer')->willReturn($container);
        $passwordPolicyValidator = self::createStub(PasswordPolicyValidator::class);
        $passwordPolicyValidator->method('isValidPassword')->willReturn(true);
        $hash = self::createStub(PasswordHashInterface::class);
        $hash->method('getHashedPassword')->willReturn('dummy-hash');
        $passwordHashFactory = self::createStub(PasswordHashFactory::class);
        $passwordHashFactory->method('getDefaultHashInstance')->willReturn($hash);
        $subject = new \ReflectionClass(MaintenanceController::class)->newInstanceWithoutConstructor();
        foreach ([
            'configurationManager' => $configurationManager,
            'lateBootService' => $lateBootService,
            'passwordPolicyValidator' => $passwordPolicyValidator,
            'passwordHashFactory' => $passwordHashFactory,
        ] as $property => $value) {
            new \ReflectionProperty(MaintenanceController::class, $property)->setValue($subject, $value);
        }
        $request = new ServerRequest()->withParsedBody(['install' => [
            'userName' => 'test-admin', 'userPassword' => 'test-password', 'userPasswordCheck' => 'test-password', 'userSystemMaintainer' => '1',
        ]]);
        self::assertSame(200, $subject->createAdminAction($request)->getStatusCode());
    }

}
