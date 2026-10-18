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

namespace TYPO3\CMS\Core\Tests\Functional\Domain\Persistence;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Tests\RecordWriteExample\Controller\BackendController;
use TYPO3Tests\RecordWriteExample\Controller\ImportController;
use TYPO3Tests\RecordWriteExample\Controller\InteroperabilityController;
use TYPO3Tests\RecordWriteExample\Controller\RegistrationController;

final class RecordWriteControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'extbase', 'fluid'];
    protected array $testExtensionsToLoad = [
        'typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write',
        'typo3/sysext/core/Tests/Functional/Fixtures/Extensions/record_write_example',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/system-user.csv');
    }

    private function action(string $controller, string $action, array $arguments, bool $frontend): \Psr\Http\Message\ResponseInterface
    {
        $type = $frontend ? SystemEnvironmentBuilder::REQUESTTYPE_FE : SystemEnvironmentBuilder::REQUESTTYPE_BE;
        $serverRequest = (new ServerRequest('https://example.org/', 'POST'))->withAttribute('applicationType', $type)->withAttribute('extbase', new ExtbaseRequestParameters());
        if ($frontend) {
            $typoScript = new FrontendTypoScript(new RootNode(), [], [], []);
            $typoScript->setSetupArray([]);
            $serverRequest = $serverRequest->withAttribute('frontend.typoscript', $typoScript);
        }
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;
        $manager = $this->get(ConfigurationManagerInterface::class);
        $manager->setRequest($serverRequest);
        $manager->setConfiguration(['extensionName' => 'RecordWriteExample', 'pluginName' => 'Pi1', 'persistence' => ['storagePid' => 1]]);
        $request = (new Request($serverRequest))->withControllerExtensionName('RecordWriteExample')->withControllerName((new \ReflectionClass($controller))->getShortName())->withControllerActionName($action)->withPluginName('Pi1')->withArguments($arguments);
        return $this->get($controller)->processRequest($request);
    }

    #[Test]
    public function frontendRegistrationMapsRequestArgumentsAndWritesWithoutBackendSession(): void
    {
        unset($GLOBALS['BE_USER']);
        $email = 'visitor@example.org';
        $invitation = $this->get(HashService::class)->hmac($email, 'record-write-registration', HashAlgo::SHA256);
        $response = $this->action(RegistrationController::class, 'register', ['name' => ' Visitor ', 'email' => $email, 'invitation' => $invitation], true);
        self::assertSame(201, $response->getStatusCode());
        $result = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Visitor', $result['name']);
        self::assertGreaterThan(2, $result['uid']);
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertSame(1, $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->count('*', 'tx_recordwrite_item', ['email' => $email]));
    }

    #[Test]
    public function frontendRegistrationReportsAnUnavailableResultWithoutRetrying(): void
    {
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\FailAfterWriteHook::class;
        $email = 'visitor@example.org';
        $invitation = $this->get(HashService::class)->hmac($email, 'record-write-registration', HashAlgo::SHA256);
        $response = $this->action(RegistrationController::class, 'register', ['name' => 'Lose result mapping', 'email' => $email, 'invitation' => $invitation], true);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('contact support before retrying', (string)$response->getBody());
        self::assertSame(1, $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->count('*', 'tx_recordwrite_item', ['email' => $email]));
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    #[Test]
    public function frontendApplicationAuthorizationRejectsInvalidInvitationsEvenForBackendAdmins(): void
    {
        $this->setUpBackendUser(1);
        $response = $this->action(RegistrationController::class, 'register', ['name' => 'Visitor', 'email' => 'visitor@example.org', 'invitation' => 'untrusted'], true);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->count('*', 'tx_recordwrite_item', []));
    }

    #[Test]
    public function frontendApplicationValidationRejectsInvalidInputBeforeWriting(): void
    {
        unset($GLOBALS['BE_USER']);
        $response = $this->action(RegistrationController::class, 'register', ['name' => '', 'email' => 'invalid', 'invitation' => 'untrusted'], true);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->count('*', 'tx_recordwrite_item', []));
    }

    #[Test]
    public function backendActionUsesTheActualBackendUserAndMapsUidArguments(): void
    {
        $user = $this->setUpBackendUser(1);
        $response = $this->action(BackendController::class, 'update', ['uid' => '1', 'title' => 'Backend update'], false);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($user, $GLOBALS['BE_USER']);
        self::assertSame('Backend update', $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->select(['title'], 'tx_recordwrite_item', ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function backendActionDoesNotUseTheSystemIdentityToCircumventRestrictions(): void
    {
        $user = $this->setUpBackendUser(1);
        $user->user['admin'] = 0;
        $user->groupData['tables_modify'] = '';
        $response = $this->action(BackendController::class, 'update', ['uid' => 1, 'title' => 'Denied'], false);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('Original', $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->select(['title'], 'tx_recordwrite_item', ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function workspaceControllerResponsesExposeLogicalAndPhysicalIdentitiesForFollowupActions(): void
    {
        $this->setUpBackendUser(1)->workspace = 1;
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $response = $this->action(BackendController::class, 'update', ['uid' => 1, 'title' => 'Workspace update'], false);
        $first = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $first['uid']);
        self::assertNotSame($first['uid'], $first['physicalUid']);
        self::assertSame(1, $first['workspace']);
        $second = $this->action(BackendController::class, 'update', ['uid' => (string)$first['uid'], 'title' => 'Follow-up'], false);
        self::assertSame($first, json_decode((string)$second->getBody(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('Original', $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->select(['title'], 'tx_recordwrite_item', ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function controllerImportCreatesAndUpdatesContentAndReportsInvalidRowsBeforeExecution(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->importCSVDataSet(__DIR__ . '/Fixtures/layouts.csv');
        $writer = $this->get(RecordWriterInterface::class);
        $writer->save($writer->editByUid('pages', 1)->set('backend_layout', '1'));
        $writer->save($writer->editByUid('pages', 2)->set('backend_layout', '2'));
        $existing = $writer->save($writer->create('tt_content', 1)->set('CType', 'text'));
        $category = $writer->save($writer->create('sys_category', 1)->set('title', 'Import category'));
        $response = $this->action(ImportController::class, 'import', ['rows' => [
            ['pid' => 1, 'header' => 'First content', 'bodytext' => '<p>First</p>', 'colPos' => 0, 'categories' => [$category->getUid()]],
            ['pid' => 2, 'header' => 'Sidebar', 'colPos' => 1],
            ['uid' => $existing->getUid(), 'header' => 'Existing updated', 'colPos' => 2],
            ['pid' => 1, 'header' => 'Invalid column', 'colPos' => 99],
        ]], false);
        $rows = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['rows'];
        self::assertSame(['unknown', 'unknown', 'unknown', 'notAttempted'], array_column($rows, 'outcome'), json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertSame(2, (int)$writer->editByUid('tt_content', $rows[1]['uid'])->get('pid'));
        self::assertSame('Existing updated', $writer->editByUid('tt_content', $existing->getUid())->get('header'));
        self::assertSame($category->getUid(), $writer->editByUid('tt_content', $rows[0]['uid'])->get('categories')[0]->uid);
        self::assertSame(3, $this->getConnectionPool()->getConnectionForTable('tt_content')->count('*', 'tt_content', []));
    }

    #[Test]
    public function controllerImportReportsPartialWritesWithoutRetryingRows(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = \TYPO3\CMS\Core\Tests\Functional\Domain\Persistence\Fixtures\FailAfterWriteHook::class;
        $response = $this->action(ImportController::class, 'import', ['rows' => [
            ['pid' => 1, 'header' => 'First persisted'],
            ['pid' => 1, 'header' => 'Fail after persistence'],
            ['pid' => 2, 'header' => 'Uncertain outcome'],
        ]], false);
        $rows = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['rows'];
        self::assertSame(['unknown', 'unknown', 'unknown'], array_column($rows, 'outcome'));
        self::assertGreaterThan(0, $rows[0]['uid']);
        self::assertGreaterThan(0, $rows[1]['uid']);
        self::assertNull($rows[2]['uid']);
        self::assertNotEmpty($rows[1]['errors']);
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable('tt_content')->count('*', 'tt_content', []));
    }

    #[Test]
    public function extbaseModelsAndRecordApiObjectsHaveSeparateIdentityMapsAndCanBeReconciled(): void
    {
        $this->setUpBackendUser(1);
        $response = $this->action(InteroperabilityController::class, 'rename', ['uid' => '1', 'title' => 'Record API change'], false);
        self::assertSame(['before' => 'Original', 'stale' => 'Original', 'reloaded' => 'Record API change', 'record' => 'Record API change'], json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }
    #[Test]
    public function backendAndImportActionsCannotBeUsedAsFrontendSystemWriteEndpoints(): void
    {
        $this->setUpBackendUser(1);
        self::assertSame(403, $this->action(BackendController::class, 'update', ['uid' => '1', 'title' => 'Forbidden'], true)->getStatusCode());
        self::assertSame(403, $this->action(ImportController::class, 'import', ['rows' => [['pid' => 1, 'header' => 'Forbidden']]], true)->getStatusCode());
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable('tt_content')->count('*', 'tt_content', []));
        self::assertSame('Original', $this->getConnectionPool()->getConnectionForTable('tx_recordwrite_item')->select(['title'], 'tx_recordwrite_item', ['uid' => 1])->fetchOne());
    }

}
