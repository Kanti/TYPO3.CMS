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

namespace TYPO3\CMS\Backend\Tests\Functional\Form\Element;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\Element\SelectCountryElement;
use TYPO3\CMS\Backend\Form\Element\SelectSingleElement;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SearchableSelectElementTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users_core.csv');
        $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $GLOBALS['BE_USER']->enablecolumns = ['deleted' => true];
        $GLOBALS['BE_USER']->setBeUserByUid(1);
        $GLOBALS['BE_USER']->initializeUserSessionManager();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
    }

    public static function searchableConfigurationDataProvider(): \Generator
    {
        yield 'single select' => [
            SelectSingleElement::class,
            [
                'type' => 'select',
                'renderType' => 'selectSingle',
            ],
            true,
        ];
        yield 'select listbox' => [
            SelectSingleElement::class,
            [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'size' => 5,
            ],
            false,
        ];
        yield 'country select' => [
            SelectCountryElement::class,
            [
                'type' => 'country',
            ],
            true,
        ];
        yield 'country listbox' => [
            SelectCountryElement::class,
            [
                'type' => 'country',
                'size' => 5,
            ],
            false,
        ];
    }

    /**
     * @param class-string<SelectSingleElement|SelectCountryElement> $elementClass
     */
    #[DataProvider('searchableConfigurationDataProvider')]
    #[Test]
    public function renderWrapsSingleSelectsAutomatically(string $elementClass, array $config, bool $expectSearchable): void
    {
        $config['items'] = [
            [
                'label' => 'Group',
                'value' => '--div--',
            ],
            [
                'label' => 'First',
                'value' => 'first',
            ],
            [
                'label' => 'Second',
                'value' => 'second',
            ],
        ];
        $node = $this->get($elementClass);
        $node->setData([
            'tableName' => 'tt_content',
            'fieldName' => 'field',
            'databaseRow' => ['uid' => 1],
            'processedTca' => [
                'columns' => [
                    'field' => ['config' => $config],
                ],
            ],
            'parameterArray' => [
                'itemFormElValue' => 'second',
                'itemFormElName' => 'data[tt_content][1][field]',
                'fieldConf' => [
                    'label' => 'Field',
                    'config' => $config,
                ],
            ],
        ]);
        $result = $node->render();

        $javaScriptModules = array_map(
            static fn(JavaScriptModuleInstruction $instruction): string => $instruction->getName(),
            $result['javaScriptModules']
        );
        self::assertMatchesRegularExpression('#<optgroup label="Group">\s*<option value="first"#', $result['html']);
        if ($expectSearchable) {
            self::assertMatchesRegularExpression('#<typo3-backend-searchable-select>\s*<select #', $result['html']);
            self::assertContains('@typo3/backend/element/searchable-select-element.js', $javaScriptModules);
        } else {
            self::assertStringNotContainsString('typo3-backend-searchable-select', $result['html']);
            self::assertNotContains('@typo3/backend/element/searchable-select-element.js', $javaScriptModules);
        }
    }
}
