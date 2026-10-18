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
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordDraftValuesTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/base.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    #[Test]
    public function originalAndPendingJsonHaveTheSamePhpRepresentation(): void
    {
        $writer = $this->get(RecordWriter::class);
        $record = $writer->save($writer->create('tx_recordwrite_item', 1)->set('payload', ['enabled' => true]));
        $draft = $writer->edit($record);
        self::assertSame(['enabled' => true], $draft->get('payload'));
        $draft->set('payload', ['enabled' => false]);
        self::assertSame(['enabled' => false], $draft->get('payload'));
        $draft->discard('payload');
        self::assertSame(['enabled' => true], $draft->get('payload'));
    }

    #[Test]
    public function dateLinkCheckboxAndRelationTypesRemainConsistent(): void
    {
        $writer = $this->get(RecordWriter::class);
        $record = $writer->save($writer->create('tx_recordwrite_item', 1)->setValues(['starttime' => 1700000000, 'link' => 'https://example.org/', 'hidden' => false]));
        $draft = $writer->edit($record);
        $originalDate = $draft->get('starttime');
        $originalLink = $draft->get('link');
        $originalHidden = $draft->get('hidden');
        $originalRelated = $draft->get('related');
        $draft->set('starttime', 1700000010)->set('link', 'https://example.org/new')->set('hidden', true);
        $draft->setRelation('related', new \TYPO3\CMS\Core\Domain\Persistence\RecordReference('tx_recordwrite_item', 2));
        $pendingDate = $draft->get('starttime');
        $pendingLink = $draft->get('link');
        $pendingHidden = $draft->get('hidden');
        $pendingRelated = $draft->get('related');
        self::assertInstanceOf(\DateTimeImmutable::class, $originalDate);
        self::assertInstanceOf(\TYPO3\CMS\Core\LinkHandling\TypolinkParameter::class, $originalLink);
        self::assertFalse($originalHidden);
        self::assertSame([], $originalRelated);
        self::assertInstanceOf(\DateTimeImmutable::class, $pendingDate);
        self::assertInstanceOf(\TYPO3\CMS\Core\LinkHandling\TypolinkParameter::class, $pendingLink);
        self::assertTrue($pendingHidden);
        self::assertSame(2, $pendingRelated[0]->uid);
    }
}
