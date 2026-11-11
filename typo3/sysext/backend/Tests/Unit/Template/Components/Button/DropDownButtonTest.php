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

namespace TYPO3\CMS\Backend\Tests\Unit\Template\Components\Button;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownItem;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DropDownButtonTest extends UnitTestCase
{
    #[Test]
    public function searchIsEnabledWithoutVisibilityConfiguration(): void
    {
        $button = new DropDownButton()->setLabel('Languages')->setShowLabelText(true)
            ->addItem(new DropDownItem()->setTag('button')->setLabel('Deutsch'));

        self::assertTrue($button->isValid());
        self::assertStringContainsString('data-dropdown-search>', $button->render());
        self::assertStringNotContainsString('data-dropdown-search-visible', $button->render());
        self::assertStringContainsString('Deutsch</button>', $button->render());
    }

}
