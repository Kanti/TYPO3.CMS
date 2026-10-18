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
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\RecordDraft;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteContext;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteTarget;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecordDraftTest extends UnitTestCase
{
    private bool $hadBackendUser;
    private mixed $previousBackendUser;

    protected function setUp(): void
    {
        parent::setUp();
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
        parent::tearDown();
    }

    private function draft(bool $existing = false): RecordDraft
    {
        $user = new BackendUserAuthentication();
        $user->user['uid'] = 1;
        $user->workspace = 0;
        $GLOBALS['BE_USER'] = $user;
        $context = new Context();
        $context->setAspect('workspace', new WorkspaceAspect(0));
        return new RecordDraft(
            'example',
            42,
            RecordWriteContext::capture($context),
            $existing ? new RecordWriteTarget('example', 1, ['uid' => 1, 'pid' => 42, 'title' => 'Original'], 0) : null
        );
    }

    #[Test]
    public function nullIsAPendingValueAndDiscardRevealsTheOriginal(): void
    {
        $draft = $this->draft(true);
        self::assertFalse($draft->hasChanges());
        $draft->set('title', 'First')->set('title', null);
        self::assertNull($draft->get('title'));
        self::assertSame(['title' => null], $draft->getChanges());
        $draft->discard('title');
        self::assertSame('Original', $draft->get('title'));
        self::assertFalse($draft->hasChanges());
    }

    #[Test]
    public function missingNewValueIsDistinctFromNull(): void
    {
        $draft = $this->draft();
        $draft->set('body', null);
        self::assertNull($draft->get('body'));
        $this->expectException(InvalidRecordValueException::class);
        $draft->get('title');
    }

    #[Test]
    public function consumedDraftRetainsDiagnosticValuesButCannotBeChanged(): void
    {
        $draft = $this->draft()->set('title', 'Attempted value');
        $draft->consume();
        self::assertSame('Attempted value', $draft->get('title'));
        $this->expectException(InvalidWriteContextException::class);
        $draft->discard('title');
    }
}
