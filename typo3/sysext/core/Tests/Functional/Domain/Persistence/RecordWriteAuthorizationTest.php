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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordWriteException;
use TYPO3\CMS\Core\Domain\Persistence\RecordReference;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordWriteAuthorizationTest extends FunctionalTestCase
{
    private const TABLE = 'tx_recordwrite_item';
    protected array $coreExtensionsToLoad = ['workspaces'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];
    private RecordWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $this->get(SiteWriter::class)->write('record-write', ['rootPageId' => 1, 'base' => 'https://example.org/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
        ]]);
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->writer = $this->get(RecordWriter::class);
    }

    public static function deniedWrites(): iterable
    {
        yield 'table' => ['table'];
        yield 'page' => ['page'];
        yield 'record edit lock' => ['record'];
        yield 'language' => ['language'];
        yield 'excluded field' => ['excluded'];
        yield 'read-only table' => ['tableReadOnly'];
        yield 'read-only field' => ['fieldReadOnly'];
        yield 'unversioned table in workspace' => ['unversioned'];
    }

    #[Test]
    #[DataProvider('deniedWrites')]
    public function readsDoNotRequireEditPermissionAndPreflightCannotPredictAllNativeChecks(string $restriction): void
    {
        $uid = 1;
        $field = $restriction === 'excluded' ? 'restricted' : ($restriction === 'fieldReadOnly' ? 'readonly_value' : 'title');
        $record = $this->writer->save($this->writer->editByUid(self::TABLE, $uid)->set('restricted', 'Readable secret'));
        if ($restriction === 'page') {
            $record = $this->writer->save($this->writer->create(self::TABLE, 3)->set('title', 'Inaccessible page'));
            $uid = $record->getUid();
        }
        if ($restriction === 'language') {
            $record = $this->writer->localize($record, 1);
            $uid = $record->getUid();
        }
        if ($restriction === 'tableReadOnly' || $restriction === 'record' || $restriction === 'unversioned') {
            $tca = $GLOBALS['TCA'];
            if ($restriction === 'tableReadOnly') {
                $tca[self::TABLE]['ctrl']['readOnly'] = true;
            } elseif ($restriction === 'unversioned') {
                $tca[self::TABLE]['ctrl']['versioningWS'] = false;
                $GLOBALS['BE_USER']->workspace = 1;
                $this->get(Context::class)->setAspect('workspace', new \TYPO3\CMS\Core\Context\WorkspaceAspect(1));
            } else {
                $tca[self::TABLE]['ctrl']['editlock'] = 'flags';
                $record = $this->writer->save($this->writer->edit($record)->set('flags', 1));
            }
            $this->get(TcaSchemaFactory::class)->rebuild($tca);
        }
        $user = $GLOBALS['BE_USER'];
        $user->user['admin'] = 0;
        $user->groupData['tables_modify'] = $restriction === 'table' ? '' : self::TABLE;
        $user->groupData['webmounts'] = '1';
        $user->groupData['allowed_languages'] = '0';
        $user->groupData['non_exclude_fields'] = '';
        foreach ([fn() => $this->writer->edit($record), fn() => $this->writer->editByUid(self::TABLE, $uid)] as $edit) {
            $draft = $edit();
            self::assertSame($record->getRawRecord()->get($field), $draft->get($field));
            $draft->set($field, 'Rejected change');
            try {
                $this->writer->save($draft);
                self::fail('The edit permission must still be enforced at save.');
            } catch (RecordWriteException) {
                self::assertNotSame('Rejected change', $this->getConnectionPool()->getConnectionForTable(self::TABLE)->select([$field], self::TABLE, ['uid' => $uid])->fetchOne());
            }
        }
        $batch = $this->writer->batch();
        $batch->editByUid(self::TABLE, $uid)->set($field, 'Invalid batch change');
        self::assertSame(in_array($restriction, ['table', 'page', 'record', 'language'], true), $batch->validate()->isValid());
    }

    public static function relationRestrictions(): iterable
    {
        yield 'table' => [false];
        yield 'excluded relation field' => [true];
    }

    #[Test]
    #[DataProvider('relationRestrictions')]
    public function relationReadsDoNotRequireEditPermissionButChangesDo(bool $excluded): void
    {
        $draft = $this->writer->editByUid(self::TABLE, 1);
        $draft->relations('related')->add(new RecordReference(self::TABLE, 2));
        $this->writer->save($draft);
        if ($excluded) {
            $tca = $GLOBALS['TCA'];
            $tca[self::TABLE]['columns']['related']['exclude'] = true;
            $this->get(TcaSchemaFactory::class)->rebuild($tca);
        }
        $user = $GLOBALS['BE_USER'];
        $user->user['admin'] = 0;
        $user->groupData['tables_modify'] = $excluded ? self::TABLE : '';
        $user->groupData['non_exclude_fields'] = '';
        $user->groupData['webmounts'] = '1';
        $draft = $this->writer->editByUid(self::TABLE, 1);
        self::assertSame([2], array_map(static fn(RecordReference $ref): int => $ref->uid, $draft->relations('related')->toArray()));
        $draft->relations('related')->clear();
        try {
            $this->writer->save($draft);
            self::fail('Relation changes require edit permissions.');
        } catch (RecordWriteException) {
            self::assertSame([2], array_map(static fn(RecordReference $ref): int => $ref->uid, $this->writer->editByUid(self::TABLE, 1)->get('related')));
        }
    }

    #[Test]
    public function aDifferentBackendContextAspectDoesNotAuthorizeOrDenyTheWriter(): void
    {
        $editor = $GLOBALS['BE_USER'];
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($editor);
        $other = new BackendUserAuthentication();
        $other->user = ['uid' => 999, 'admin' => 0];
        $aspect = new UserAspect($other);
        $context = $this->get(Context::class);
        $context->setAspect('backend.user', $aspect);
        Fixtures\CaptureWriteIdentityHook::reset();
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Fixtures\CaptureWriteIdentityHook::class;
        $record = $this->writer->save($this->writer->editByUid(self::TABLE, 1)->set('title', 'Actual editor'));
        self::assertSame('Actual editor', $record->get('title'));
        self::assertSame($editor, $GLOBALS['BE_USER']);
        self::assertSame($aspect, $context->getAspect('backend.user'));
        self::assertNotEmpty(Fixtures\CaptureWriteIdentityHook::observations());
        foreach (Fixtures\CaptureWriteIdentityHook::observations() as $observed) {
            self::assertSame([$editor, $editor, 1], $observed);
        }
        self::assertSame(1, (int)$this->getConnectionPool()->getConnectionForTable('sys_history')->select(['userid'], 'sys_history', ['tablename' => self::TABLE, 'recuid' => 1])->fetchOne());
    }
}
