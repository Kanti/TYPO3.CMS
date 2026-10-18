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
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\RecordReference;
use TYPO3\CMS\Core\Domain\Persistence\RecordRelationSet;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecordRelationSetTest extends UnitTestCase
{
    #[Test]
    public function orderedSetDoesNotDuplicateMembersAndRemovePreservesOthers(): void
    {
        $one = new RecordReference('example', 1);
        $two = new RecordReference('example', 2);
        $set = new RecordRelationSet([$one], static fn($reference) => $reference, static fn() => null);
        $set->add($two)->add($one)->remove($one);
        self::assertSame([$two], $set->toArray());
        $set->replace([$one, $two])->reorder([$two, $one]);
        self::assertSame([$two, $one], $set->toArray());
        $set->clear();
        self::assertSame([], $set->toArray());
    }

    #[Test]
    public function reorderingCannotIntroduceOrRemoveMembers(): void
    {
        $one = new RecordReference('example', 1);
        $two = new RecordReference('example', 2);
        $set = new RecordRelationSet([$one], static fn($reference) => $reference, static fn() => null);
        $this->expectException(InvalidRecordValueException::class);
        $set->reorder([$one, $two]);
    }

    #[Test]
    public function invalidReferencesAreRejected(): void
    {
        $this->expectException(InvalidRecordValueException::class);
        new RecordReference('example', 0);
    }
}
