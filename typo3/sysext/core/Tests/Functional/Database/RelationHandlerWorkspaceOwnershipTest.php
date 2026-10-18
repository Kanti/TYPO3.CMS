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

namespace TYPO3\CMS\Core\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RelationHandlerWorkspaceOwnershipTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];
    protected array $testExtensionsToLoad = ['typo3/sysext/core/Tests/Functional/Fixtures/Extensions/test_record_write'];

    public static function workspaceOwnership(): iterable
    {
        yield 'Live' => [0, true, [3, 7]];
        yield 'Workspace with logical IDs' => [1, true, [5, 7]];
        yield 'Workspace with physical IDs' => [1, false, [6, 9]];
        yield 'Other workspace' => [2, true, [3]];
    }

    #[Test]
    #[DataProvider('workspaceOwnership')]
    public function foreignFieldMembershipUsesWorkspaceOwnership(int $workspace, bool $liveIds, array $expected): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RelationHandler/workspaceOwnership.csv');
        $handler = GeneralUtility::makeInstance(RelationHandler::class);
        $handler->setWorkspaceId($workspace);
        $handler->setUseLiveReferenceIds($liveIds);
        $field = $this->get(TcaSchemaFactory::class)->get('tx_recordwrite_item')->getField('children');
        $handler->initializeForField('tx_recordwrite_item', $field, 1);
        self::assertSame($expected, array_map(static fn(array $item): int => (int)$item['id'], $handler->itemArray));
    }
}
