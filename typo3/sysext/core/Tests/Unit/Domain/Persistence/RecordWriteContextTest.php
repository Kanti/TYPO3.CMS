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
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteContext;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecordWriteContextTest extends UnitTestCase
{
    private mixed $previousRequest;
    private bool $hadBackendUser;
    private mixed $previousBackendUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->hadBackendUser = array_key_exists('BE_USER', $GLOBALS);
        $this->previousBackendUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadBackendUser) {
            $GLOBALS['BE_USER'] = $this->previousBackendUser;
        } else {
            unset($GLOBALS['BE_USER']);
        }
        if ($this->previousRequest !== null) {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        } else {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
        parent::tearDown();
    }

    #[Test]
    public function fallbackChangesAreDetectedEvenWhenLanguageIdsStayTheSame(): void
    {
        $user = new BackendUserAuthentication();
        $user->user['uid'] = 1;
        $user->workspace = 0;
        $GLOBALS['BE_USER'] = $user;
        $GLOBALS['TYPO3_REQUEST'] = (new \TYPO3\CMS\Core\Http\ServerRequest())->withAttribute('applicationType', \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $context = new Context();
        $context->setAspect('workspace', new WorkspaceAspect(0));
        $context->setAspect('language', new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_MIXED, [0]));
        $captured = RecordWriteContext::capture($context);
        $context->setAspect('language', new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_MIXED, [2, 0]));
        $this->expectException(InvalidWriteContextException::class);
        $captured->assertCurrent($context);
    }
}
