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

namespace TYPO3\CMS\Core\Tests\Unit\Domain\Persistence;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Domain\Persistence\RecordDraft;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteContext;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3Tests\RecordWriteExample\Controller\RegistrationController;
use TYPO3Tests\RecordWriteExample\Service\RegistrationSummary;

final class RecordWriterInterfaceTest extends UnitTestCase
{
    #[Test]
    public function extensionControllerCanMockTheWriterContractWithoutDataHandlerOrDatabase(): void
    {
        $previousUser = $GLOBALS['BE_USER'] ?? null;
        $previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        try {
            $user = new BackendUserAuthentication();
            $user->user['uid'] = 1;
            $user->workspace = 0;
            $GLOBALS['BE_USER'] = $user;
            $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
            $draft = new RecordDraft('tx_recordwrite_item', 1, RecordWriteContext::capture(new Context()));
        } finally {
            if ($previousUser !== null) {
                $GLOBALS['BE_USER'] = $previousUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }
            if ($previousRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $previousRequest;
            } else {
                unset($GLOBALS['TYPO3_REQUEST']);
            }
        }
        $record = $this->createMock(RecordInterface::class);
        $record->method('getUid')->willReturn(123);
        $record->method('get')->with('title')->willReturn('Visitor');
        $writer = $this->createMock(RecordWriterInterface::class);
        $writer->expects($this->once())->method('create')->with('tx_recordwrite_item', 1)->willReturn($draft);
        $writer->expects($this->once())->method('save')->with(self::callback(static fn(RecordDraft $pending): bool => $pending->get('title') === 'Visitor' && $pending->get('email') === 'visitor@example.org'))->willReturn($record);
        $controller = new RegistrationController($writer, new RegistrationSummary());
        $controller->injectResponseFactory(new ResponseFactory());
        $controller->injectStreamFactory(new StreamFactory());
        $previousEncryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'record-write-unit-test';
        $hash = new HashService();
        $controller->injectHashService($hash);
        (new \ReflectionProperty($controller, 'request'))->setValue($controller, new Request((new ServerRequest('https://example.org/', 'POST'))->withAttribute('extbase', new \TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters())));
        $invitation = $hash->hmac('visitor@example.org', 'record-write-registration', HashAlgo::SHA256);
        try {
            $response = $controller->registerAction(' Visitor ', 'VISITOR@example.org', $invitation);
        } finally {
            if ($previousEncryptionKey !== null) {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $previousEncryptionKey;
            } else {
                unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
            }
        }
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['uid' => 123, 'name' => 'Visitor'], json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }
}
