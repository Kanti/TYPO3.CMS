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
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownHeader;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownItem;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DropDownButtonTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private function createDropDownButton(): DropDownButton
    {
        return new DropDownButton()
            ->setLabel('Show <configuration>')
            ->setShowLabelText(true)
            ->addItem(new DropDownHeader()->setLabel('Group'))
            ->addItem(new DropDownItem()->setLabel('First')->setHref('/first'));
    }

    #[Test]
    public function renderWrapsDropDownAndLoadsSearchAutomatically(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects($this->once())->method('loadJavaScriptModule')
            ->with('@typo3/backend/element/searchable-select-element.js');
        GeneralUtility::setSingletonInstance(PageRenderer::class, $pageRenderer);

        $html = $this->createDropDownButton()->render();

        self::assertStringStartsWith('<typo3-backend-searchable-select label="Show &lt;configuration&gt;"><div class="btn-group">', $html);
        self::assertStringContainsString('<li><h6 class="dropdown-header">Group</h6></li>', $html);
        self::assertStringEndsWith('</ul></div></typo3-backend-searchable-select>', $html);
    }
}
