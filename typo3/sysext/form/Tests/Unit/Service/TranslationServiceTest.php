<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Form\Tests\Unit\Mvc\Service;

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

use Prophecy\Argument;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageStore;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Form\Domain\Model\FormElements\GenericFormElement;
use TYPO3\CMS\Form\Domain\Model\FormElements\Page;
use TYPO3\CMS\Form\Domain\Model\Renderable\RootRenderableInterface;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use TYPO3\CMS\Form\Service\TranslationService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case
 */
class TranslationServiceTest extends UnitTestCase
{
    /**
     * @var array A backup of registered singleton instances
     */
    protected $singletonInstances = [];

    /**
     * @var ConfigurationManager
     */
    protected $mockConfigurationManager;

    /**
     * @var TranslationService
     */
    protected $mockTranslationService;

    /**
     * @var LanguageStore
     */
    protected $store;

    /**
     * Set up
     */
    public function setUp()
    {
        $this->singletonInstances = GeneralUtility::getSingletonInstances();

        $cacheManagerProphecy = $this->prophesize(CacheManager::class);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManagerProphecy->reveal());
        $cacheFrontendProphecy = $this->prophesize(FrontendInterface::class);
        $cacheManagerProphecy->getCache('l10n')->willReturn($cacheFrontendProphecy->reveal());
        $cacheFrontendProphecy->get(Argument::cetera())->willReturn(false);
        $cacheFrontendProphecy->set(Argument::cetera())->willReturn(null);

        $this->mockConfigurationManager = $this->getAccessibleMock(ConfigurationManager::class, [
            'getConfiguration'
        ], [], '', false);

        $this->mockTranslationService = $this->getAccessibleMock(TranslationService::class, [
            'getConfigurationManager',
            'getLanguageService'
        ], [], '', false);

        $this->mockTranslationService
            ->expects($this->any())
            ->method('getLanguageService')
            ->willReturn(GeneralUtility::makeInstance(LanguageService::class));

        $this->mockTranslationService
            ->expects($this->any())
            ->method('getConfigurationManager')
            ->willReturn($this->mockConfigurationManager);

        $this->store = GeneralUtility::makeInstance(LanguageStore::class);
        $this->store->initialize();
    }

    /**
     * Tear down
     */
    public function tearDown(): void
    {
        GeneralUtility::resetSingletonInstances($this->singletonInstances);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function translateReturnsExistingDefaultLanguageKeyIfFullExtDefaultLanguageKeyIsRequested(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = 'FORM EN';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            $xlfPath . ':element.Page.renderingOptions.nextButtonLabel'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsExistingDefaultLanguageKeyIfFullLLLExtDefaultLanguageKeyIsRequested(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = 'FORM EN';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            'LLL:' . $xlfPath . ':element.Page.renderingOptions.nextButtonLabel'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsExistingDefaultLanguageKeyIfDefaultLanguageKeyIsRequestedAndDefaultValueIsGiven(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = 'FORM EN';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            $xlfPath . ':element.Page.renderingOptions.nextButtonLabel',
            null,
            null,
            null,
            'defaultValue'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsEmptyStringIfNonExistingDefaultLanguageKeyIsRequested(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $this->store->flushData($xlfPath);

        $expected = '';
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            $xlfPath . ':element.Page.renderingOptions.nonExisting'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsDefaultValueIfNonExistingDefaultLanguageKeyIsRequestedAndDefaultValueIsGiven(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $this->store->flushData($xlfPath);

        $expected = 'defaultValue';
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            $xlfPath . ':element.Page.renderingOptions.nonExisting',
            null,
            null,
            null,
            'defaultValue'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsExistingLanguageKeyForLanguageIfExtPathLanguageKeyIsRequested(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = 'FORM DE';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            $xlfPath . ':element.Page.renderingOptions.nextButtonLabel',
            null,
            null,
            'de'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsDefaultValueIfNonExistingLanguageKeyForLanguageIsRequestedAndDefaultValueIsGiven(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = 'defaultValue';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            $xlfPath . ':element.Page.renderingOptions.nonExisting',
            null,
            null,
            'de',
            'defaultValue'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsEmptyStringIfNonExistingLanguageKeyForLanguageIsRequested(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = '';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            $xlfPath . ':element.Page.renderingOptions.nonExisting',
            null,
            null,
            'de'
        ));
    }

    /**
     * @test
     */
    public function translateReturnsExistingDefaultLanguageKeyIfDefaultLanguageKeyIsRequestedAndExtFilePathIsGiven(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = 'FORM EN';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            'element.Page.renderingOptions.nextButtonLabel',
            null,
            $xlfPath
        ));
    }

    /**
     * @test
     */
    public function translateReturnsExistingDefaultLanguageKeyIfDefaultLanguageKeyIsRequestedAndLLLExtFilePathIsGiven(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $expected = 'FORM EN';

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translate',
            'element.Page.renderingOptions.nextButtonLabel',
            null,
            'LLL:' . $xlfPath
        ));
    }

    /**
     * @test
     */
    public function translateValuesRecursiveTranslateRecursive(): void
    {
        $xlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';

        $input = [
            'Stan' => [
                'Steve' => 'Roger'
            ],
            [
                'Francine' => [
                    'Klaus' => 'element.Page.renderingOptions.nextButtonLabel'
                ],
            ],
        ];

        $expected = [
            'Stan' => [
                'Steve' => 'Roger'
            ],
            [
                'Francine' => [
                    'Klaus' => 'FORM EN'
                ],
            ],
        ];

        $this->store->flushData($xlfPath);
        $this->assertEquals($expected, $this->mockTranslationService->_call(
            'translateValuesRecursive',
            $input,
            $xlfPath
        ));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateLabelForConcreteFormAndConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementLabelIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $expected = 'form-element-identifier LABEL EN';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', 'some label');

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateLabelForConcreteFormAndConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementLabelIsEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $expected = 'form-element-identifier LABEL EN';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', '');

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueNotTranslateLabelForConcreteFormAndConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementLabelIsEmptyAndPropertyShouldNotBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => false
            ],
        ];

        $expected = '';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', '');

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateLabelForConcreteFormElementIfElementRenderingOptionsContainsATranslationFileAndElementLabelIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'another-form-runtime-identifier';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $expected = 'form-element-identifier LABEL EN 1';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', 'some label');

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateLabelForFormElementTypeIfElementRenderingOptionsContainsATranslationFileAndElementLabelIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'another-form-runtime-identifier';
        $formElementIdentifier = 'another-form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $expected = 'form-element-identifier LABEL EN 2';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', 'some label');

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslatePropertyForConcreteFormAndConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementPropertyIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];
        $formElementProperties = [
            'placeholder' => 'placeholder',
        ];

        $expected = 'form-element-identifier PLACEHOLDER EN';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('properties', $formElementProperties);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['placeholder'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueNotTranslatePropertyForConcreteFormAndConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementPropertyIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationNotExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'another-form-runtime-identifier';
        $formElementIdentifier = 'another-form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];
        $formElementProperties = [
            'placeholder' => 'placeholder',
        ];

        $expected = 'placeholder';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('properties', $formElementProperties);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['placeholder'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateRenderingOptionForConcreteFormAndConcreteSectionElementIfElementRenderingOptionsContainsATranslationFileAndElementRenderingOptionIsNotEmptyAndRenderingOptionShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $formElementIdentifier = 'form-element-identifier-page';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'nextButtonLabel' => 'next button label',
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];
        $formElementProperties = [
            'placeholder' => 'placeholder',
        ];

        $expected = 'form-element-identifier nextButtonLabel EN';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(Page::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Page');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('properties', $formElementProperties);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['nextButtonLabel'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateOptionsPropertyForConcreteFormAndConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementOptionsPropertyIsAnArrayAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $formElementIdentifier = 'options-form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];
        $formElementProperties = [
            'options' => [
                'optionValue1' => 'optionLabel1',
                'optionValue2' => 'optionLabel2'
            ],
        ];

        $expected = [
            'optionValue1' => 'options-form-element-identifier option 1 EN',
            'optionValue2' => 'options-form-element-identifier option 2 EN'
        ];

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('properties', $formElementProperties);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['options'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateOptionsPropertyForConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementOptionsPropertyIsAnArrayAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'another-form-runtime-identifier';
        $formElementIdentifier = 'options-form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];
        $formElementProperties = [
            'options' => [
                'optionValue1' => 'optionLabel1',
                'optionValue2' => 'optionLabel2'
            ],
        ];

        $expected = [
            'optionValue1' => 'options-form-element-identifier option 1 EN',
            'optionValue2' => 'options-form-element-identifier option 2 EN'
        ];

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('properties', $formElementProperties);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['options'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFinisherOptionTranslateOptionForConcreteFormIfFinisherTranslationOptionsContainsATranslationFileAndFinisherOptionIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $finisherIdentifier = 'SaveToDatabaseFinisher';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $finisherRenderingOptions = [
            'translationFile' => $textElementXlfPath,
            'translatePropertyValueIfEmpty' => true
        ];

        $expected = 'form-element-identifier SaveToDatabase subject EN';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFinisherOption', $mockFormRuntime, $finisherIdentifier, 'subject', 'subject value', $finisherRenderingOptions));
    }

    /**
     * @test
     */
    public function translateFinisherOptionTranslateOptionIfFinisherTranslationOptionsContainsATranslationFileAndFinisherOptionIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'another-form-runtime-identifier';
        $finisherIdentifier = 'SaveToDatabaseFinisher';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $finisherRenderingOptions = [
            'translationFile' => $textElementXlfPath,
            'translatePropertyValueIfEmpty' => true
        ];

        $expected = 'form-element-identifier SaveToDatabase subject EN 1';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFinisherOption', $mockFormRuntime, $finisherIdentifier, 'subject', 'subject value', $finisherRenderingOptions));
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateLabelForConcreteFormAndConcreteElementFromFormRumtimeTranslationFileIfElementRenderingOptionsContainsNoTranslationFileAndElementLabelIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'my-form-runtime-identifier';
        $formElementIdentifier = 'my-form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [];

        $expected = 'my-form-runtime-identifier my-form-element-identifier LABEL EN';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', 'some label');

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function supportsArgumentsForFormElementValueTranslations(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';

        $this->store->flushData($formRuntimeXlfPath);

        /** @var FormRuntime|\Prophecy\Prophecy\ObjectProphecy */
        $formRuntime = $this->prophesize(FormRuntime::class);
        $formRuntime->getIdentifier()->willReturn('my-form-runtime-identifier');
        $formRuntime->getRenderingOptions()->willReturn([
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true,
            ],
        ]);

        /** @var RootRenderableInterface|\Prophecy\Prophecy\ObjectProphecy */
        $element = $this->prophesize(RootRenderableInterface::class);
        $element->getIdentifier()->willReturn('my-form-element-with-translation-arguments');
        $element->getType()->willReturn(RootRenderableInterface::class);
        $element->getLabel()->willReturn('See %s or %s');
        $element->getRenderingOptions()->willReturn([
            'translation' => [
                'arguments' => [
                        'label' => [
                            'this',
                            'that',
                        ],
                ],
            ],
        ]);

        $expected = 'See this or that';
        $result = $this->mockTranslationService->_call('translateFormElementValue', $element->reveal(), ['label'], $formRuntime->reveal());

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function translateFinisherOptionTranslateOptionForConcreteFormFromFormRuntimeIfFinisherTranslationOptionsContainsNoTranslationFileAndFinisherOptionIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf';

        $formRuntimeIdentifier = 'my-form-runtime-identifier';
        $finisherIdentifier = 'SaveToDatabaseFinisher';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $finisherRenderingOptions = [];

        $expected = 'my-form-runtime-identifier form-element-identifier SaveToDatabase subject EN';

        $this->store->flushData($formRuntimeXlfPath);
        $this->store->flushData($textElementXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFinisherOption', $mockFormRuntime, $finisherIdentifier, 'subject', 'subject value', $finisherRenderingOptions));
    }

    /**
     * @test
     */
    public function supportsArgumentsForFinisherOptionTranslations(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';

        $this->store->flushData($formRuntimeXlfPath);

        /** @var FormRuntime|\Prophecy\Prophecy\ObjectProphecy */
        $formRuntime = $this->prophesize(FormRuntime::class);
        $formRuntime->getIdentifier()->willReturn('my-form-runtime-identifier');
        $formRuntime->getRenderingOptions()->willReturn([
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true,
            ],
        ]);
        $renderingOptions = [
            'arguments' => [
                'subject' => [
                    'awesome',
                ],
            ],
        ];

        $expected = 'My awesome subject';
        $result = $this->mockTranslationService->_call('translateFinisherOption', $formRuntime->reveal(), 'EmailToReceiverWithTranslationArguments', 'subject', 'My %s subject', $renderingOptions);

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function translateFormElementValueTranslateLabelFromAdditionalTranslationForConcreteFormAndConcreteElementIfElementRenderingOptionsContainsATranslationFileAndElementLabelIsNotEmptyAndPropertyShouldBeTranslatedAndTranslationExists(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_form.xlf';
        $textElementXlfPaths = [
            10 => 'EXT:form/Tests/Unit/Service/Fixtures/locallang_text.xlf',
            20 => 'EXT:form/Tests/Unit/Service/Fixtures/locallang_additional_text.xlf'
         ];

        $formRuntimeIdentifier = 'form-runtime-identifier';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $formElementRenderingOptions = [
            'translation' => [
                'translationFile' => $textElementXlfPaths,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $expected = 'form-element-identifier ADDITIONAL LABEL EN';

        $this->store->flushData($formRuntimeXlfPath);

        foreach ($textElementXlfPaths as $textElementXlfPath) {
            $this->store->flushData($textElementXlfPath);
        }

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('renderingOptions', $formElementRenderingOptions);
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', 'some label');

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions'], [], '', false);
        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);

        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementTranslateFormWithContentElementUidIfFormContainsNoOriginalIdentifier(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_ceuid_suffix_01.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier-42';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'submitButtonLabel' => '',
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $this->store->flushData($formRuntimeXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions', 'getType'], [], '', false);

        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);
        $mockFormRuntime->expects($this->any())->method('getType')->willReturn('Form');

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', '');

        $expected = 'form-runtime-identifier-42 submitButtonLabel EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormRuntime, ['submitButtonLabel'], $mockFormRuntime));

        $expected = 'form-runtime-identifier-42 form-element-identifierlabel EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementTranslateFormWithContentElementUidIfFormContainsOriginalIdentifier(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_ceuid_suffix_02.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier-42';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'submitButtonLabel' => '',
            '_originalIdentifier' => 'form-runtime-identifier',
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $this->store->flushData($formRuntimeXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions', 'getType'], [], '', false);

        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);
        $mockFormRuntime->expects($this->any())->method('getType')->willReturn('Form');

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', '');

        $expected = 'form-runtime-identifier submitButtonLabel EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormRuntime, ['submitButtonLabel'], $mockFormRuntime));

        $expected = 'form-runtime-identifier form-element-identifierlabel EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementValue', $mockFormElement, ['label'], $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementErrorTranslateErrorFromFormWithContentElementUidIfFormContainsNoOriginalIdentifier(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_ceuid_suffix_01.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier-42';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $this->store->flushData($formRuntimeXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions', 'getType', 'getProperties'], [], '', false);

        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);
        $mockFormRuntime->expects($this->any())->method('getType')->willReturn('Form');
        $mockFormRuntime->expects($this->any())->method('getProperties')->willReturn([]);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', '');
        $mockFormElement->_set('properties', []);

        $expected = 'form-runtime-identifier-42 error 123 EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementError', $mockFormRuntime, 123, [], 'default value', $mockFormRuntime));

        $expected = 'form-runtime-identifier-42 form-element-identifier error 123 EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementError', $mockFormElement, 123, [], 'default value', $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFormElementErrorTranslateErrorFromFormWithContentElementUidIfFormContainsOriginalIdentifier(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_ceuid_suffix_02.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier-42';
        $formElementIdentifier = 'form-element-identifier';

        $formRuntimeRenderingOptions = [
            '_originalIdentifier' => 'form-runtime-identifier',
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $this->store->flushData($formRuntimeXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions', 'getType', 'getProperties'], [], '', false);

        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);
        $mockFormRuntime->expects($this->any())->method('getType')->willReturn('Form');
        $mockFormRuntime->expects($this->any())->method('getProperties')->willReturn([]);

        $mockFormElement = $this->getAccessibleMock(GenericFormElement::class, ['dummy'], [], '', false);

        $mockFormElement->_set('type', 'Text');
        $mockFormElement->_set('identifier', $formElementIdentifier);
        $mockFormElement->_set('label', '');
        $mockFormElement->_set('properties', []);

        $expected = 'form-runtime-identifier error 123 EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementError', $mockFormRuntime, 123, [], 'default value', $mockFormRuntime));

        $expected = 'form-runtime-identifier form-element-identifier error 123 EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFormElementError', $mockFormElement, 123, [], 'default value', $mockFormRuntime));
    }

    /**
     * @test
     */
    public function translateFinisherOptionTranslateOptionFromFormWithContentElementUidIfFormContainsNoOriginalIdentifier(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_ceuid_suffix_01.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier-42';

        $formRuntimeRenderingOptions = [
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $this->store->flushData($formRuntimeXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions', 'getType', 'getProperties'], [], '', false);

        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);
        $mockFormRuntime->expects($this->any())->method('getType')->willReturn('Form');
        $mockFormRuntime->expects($this->any())->method('getProperties')->willReturn([]);

        $expected = 'form-runtime-identifier-42 FooFinisher test EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFinisherOption', $mockFormRuntime, 'Foo', 'test', 'value', []));
    }

    /**
     * @test
     */
    public function translateFinisherOptionTranslateOptionFromFormWithContentElementUidIfFormContainsOriginalIdentifier(): void
    {
        $formRuntimeXlfPath = 'EXT:form/Tests/Unit/Service/Fixtures/locallang_ceuid_suffix_02.xlf';

        $formRuntimeIdentifier = 'form-runtime-identifier-42';

        $formRuntimeRenderingOptions = [
            '_originalIdentifier' => 'form-runtime-identifier',
            'translation' => [
                'translationFile' => $formRuntimeXlfPath,
                'translatePropertyValueIfEmpty' => true
            ],
        ];

        $this->store->flushData($formRuntimeXlfPath);

        $mockFormRuntime = $this->getAccessibleMock(FormRuntime::class, ['getIdentifier', 'getRenderingOptions', 'getType', 'getProperties'], [], '', false);

        $mockFormRuntime->expects($this->any())->method('getIdentifier')->willReturn($formRuntimeIdentifier);
        $mockFormRuntime->expects($this->any())->method('getRenderingOptions')->willReturn($formRuntimeRenderingOptions);
        $mockFormRuntime->expects($this->any())->method('getType')->willReturn('Form');
        $mockFormRuntime->expects($this->any())->method('getProperties')->willReturn([]);

        $expected = 'form-runtime-identifier FooFinisher test EN';
        $this->assertEquals($expected, $this->mockTranslationService->_call('translateFinisherOption', $mockFormRuntime, 'Foo', 'test', 'value', []));
    }
}
