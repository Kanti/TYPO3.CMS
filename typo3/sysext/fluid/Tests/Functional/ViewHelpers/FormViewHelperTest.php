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

namespace TYPO3\CMS\Fluid\Tests\Functional\ViewHelpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewScope;
use TYPO3\CMS\Core\Preview\PreviewToken;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\Tests\Functional\Fixtures\ViewHelpers\ExtendsAbstractEntity;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Middleware\PreviewAccess;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

final class FormViewHelperTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const array LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    ];

    protected bool $initializeDatabase = false;

    public static function isRenderedDataProvider(): array
    {
        return [
            '#1' => [
                '<f:form action="{action}" method="{method}" fieldNamePrefix="{fieldNamePrefix}" />',
                [
                    'action' => 'fieldNamePrefix<>&"\'',
                    'method' => 'fieldNamePrefix<>&"\'',
                    'fieldNamePrefix' => 'fieldNamePrefix<>&"\'',
                ],
                // first element having "@extension" in name attribute
                '<input type="hidden" name="fieldNamePrefix&lt;&gt;&amp;&quot;&#039;[__referrer][@extension]" value="" >',
            ],
            '#2' => [
                '{f:form(action:action, method:method, fieldNamePrefix:fieldNamePrefix)}',
                [
                    'action' => 'fieldNamePrefix<>&"\'',
                    'method' => 'fieldNamePrefix<>&"\'',
                    'fieldNamePrefix' => 'fieldNamePrefix<>&"\'',
                ],
                // first element having "@extension" in name attribute
                '<input type="hidden" name="fieldNamePrefix&lt;&gt;&amp;&quot;&#039;[__referrer][@extension]" value="" >',
            ],
        ];
    }

    #[DataProvider('isRenderedDataProvider')]
    #[Test]
    public function isRendered(string $source, array $variables, string $expectation): void
    {
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource($source);
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $view = new TemplateView($context);
        $view->assignMultiple($variables);
        $body = $view->render();
        $actual = null;
        if (preg_match('#<input[^>]+name=".+\[@extension\]"[^>]+>#m', $body, $matches)) {
            $actual = $matches[0];
        }
        self::assertSame($expectation, $actual);
    }

    #[Test]
    public function renderHiddenIdentityFieldReturnsAHiddenInputFieldContainingTheObjectsUID(): void
    {
        $extendsAbstractEntity = new ExtendsAbstractEntity();
        $extendsAbstractEntity->_setProperty('uid', 123);
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form fieldNamePrefix="prefix" objectName="myObjectName" object="{object}" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $view = new TemplateView($context);
        $view->assign('object', $extendsAbstractEntity);
        $expected = '<input type="hidden" name="prefix[myObjectName][__identity]" value="123" >';
        self::assertStringContainsString($expected, $view->render());
    }

    #[Test]
    public function setFormActionUriRespectsOverriddenArgument(): void
    {
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form actionUri="foobar" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $expected = '<form action="foobar" method="post">';
        self::assertStringContainsString($expected, new TemplateView($context)->render());
    }

    #[Test]
    public function nameAttributeIsSetIfGiven(): void
    {
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form name="myForm" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $expected = '<form action="" method="post" name="myForm">';
        self::assertStringContainsString($expected, new TemplateView($context)->render());
    }

    #[Test]
    public function emptyNameAttributeIsNotSet(): void
    {
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form name="" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $expected = '<form action="" method="post">';
        self::assertStringContainsString($expected, new TemplateView($context)->render());
    }

    #[Test]
    public function nameArgumentIsUsedFormHiddenIdentityName(): void
    {
        $extendsAbstractEntity = new ExtendsAbstractEntity();
        $extendsAbstractEntity->_setProperty('uid', 123);
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form name="formName" fieldNamePrefix="prefix" object="{object}" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $view = new TemplateView($context);
        $view->assign('object', $extendsAbstractEntity);
        $expected = '<input type="hidden" name="prefix[formName][__identity]" value="123" >';
        self::assertStringContainsString($expected, $view->render());
    }

    #[Test]
    public function objectNameArgumentOverrulesNameArgument(): void
    {
        $extendsAbstractEntity = new ExtendsAbstractEntity();
        $extendsAbstractEntity->_setProperty('uid', 123);
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form name="formName" fieldNamePrefix="prefix" objectName="myObjectName" object="{object}" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $view = new TemplateView($context);
        $view->assign('object', $extendsAbstractEntity);
        $expected = '<input type="hidden" name="prefix[myObjectName][__identity]" value="123" >';
        self::assertStringContainsString($expected, $view->render());
    }

    #[Test]
    public function renderWrapsHiddenFieldsWithDivForXhtmlCompatibilityWithRewrittenPropertyMapper(): void
    {
        $extendsAbstractEntity = new ExtendsAbstractEntity();
        $extendsAbstractEntity->_setProperty('uid', 123);
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form fieldNamePrefix="prefix" objectName="myObjectName" object="{object}" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $view = new TemplateView($context);
        $view->assign('object', $extendsAbstractEntity);
        $expected = '<form action="" method="post">' . chr(10) . '<div>';
        self::assertStringContainsString($expected, $view->render());
    }

    #[Test]
    public function renderWrapsHiddenFieldsWithDivAndAnAdditionalClassForXhtmlCompatibilityWithRewrittenPropertyMapper(): void
    {
        $extendsAbstractEntity = new ExtendsAbstractEntity();
        $extendsAbstractEntity->_setProperty('uid', 123);
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form hiddenFieldClassName="hidden" fieldNamePrefix="prefix" objectName="myObjectName" object="{object}" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $view = new TemplateView($context);
        $view->assign('object', $extendsAbstractEntity);
        $expected = '<form action="" method="post">' . chr(10) . '<div class="hidden">';
        self::assertStringContainsString($expected, $view->render());
    }

    #[Test]
    public function renderHiddenReferrerFieldsAddCurrentControllerAndActionAsHiddenFields1(): void
    {
        $extbaseRequestParameters = new ExtbaseRequestParameters();
        $extbaseRequestParameters->setControllerActionName('controllerActionName');
        $extbaseRequestParameters->setControllerName('controllerName');
        $extbaseRequestParameters->setControllerExtensionName('extensionName');
        $psr7Request = $this->createRequest()->withAttribute('extbase', $extbaseRequestParameters);
        $this->get(ConfigurationManagerInterface::class)->setRequest($psr7Request);
        $extbaseRequest = new Request($psr7Request);
        $extendsAbstractEntity = new ExtendsAbstractEntity();
        $extendsAbstractEntity->_setProperty('uid', 123);
        $context = $this->get(RenderingContextFactory::class)->create([], $extbaseRequest);
        $context->getTemplatePaths()->setTemplateSource('<f:form fieldNamePrefix="prefix" objectName="myObjectName" object="{object}" />');
        $view = new TemplateView($context);
        $view->assign('object', $extendsAbstractEntity);
        $expected = '<form action="" method="post">
<div>
<input type="hidden" name="prefix[myObjectName][__identity]" value="123" >

<input type="hidden" name="prefix[__referrer][@extension]" value="extensionName" >
<input type="hidden" name="prefix[__referrer][@controller]" value="controllerName" >
<input type="hidden" name="prefix[__referrer][@action]" value="controllerActionName" >
<input type="hidden" name="prefix[__referrer][arguments]" value="YTowOnt9f989c1910ba0da37460b71147bf7dee693f0508269fbec73671c8a74b39d1a6c" >
<input type="hidden" name="prefix[__referrer][@request]" value="{&quot;@extension&quot;:&quot;extensionName&quot;,&quot;@controller&quot;:&quot;controllerName&quot;,&quot;@action&quot;:&quot;controllerActionName&quot;}56fe63bb220641c2178ef3f1d29c7d8b506eb032ca8b20a085e3bd6427918ed6" >
<input type="hidden" name="prefix[__trustedProperties]" value="{&quot;myObjectName&quot;:{&quot;__identity&quot;:1}}2d7c45d172ee496869555c905578e4ad506d76c6a97342f533c64b3f88478fd4" >
</div>
</form>';
        self::assertSame($expected, $view->render());
    }

    public static function previewKeepsFormInFrameDataProvider(): array
    {
        return [
            'top' => ['_top', '_self'],
            'parent' => [' _Parent ', '_self'],
            'blank' => ['_blank', '_blank'],
        ];
    }

    #[DataProvider('previewKeepsFormInFrameDataProvider')]
    #[Test]
    public function previewKeepsFormInFrame(string $target, string $expectedTarget): void
    {
        $request = $this->createRequest(true);
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form actionUri="/search" target="' . $target . '" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        self::assertStringContainsString('target="' . $expectedTarget . '"', new TemplateView($context)->render());
    }

    #[Test]
    public function formTargetIsKeptWithoutPreview(): void
    {
        $request = $this->createRequest();
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form actionUri="/search" target="_top" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        self::assertStringContainsString('target="_top"', new TemplateView($context)->render());
    }

    public static function previewTokenIsAddedToFormsSentViaGetToHostsOfThePreviewDataProvider(): array
    {
        return [
            'address relative to the page' => ['/search'],
            'address on the host of the page' => ['https://site.example/search'],
            'address on another host of the preview' => ['https://other.example/search'],
            'address relative to the scheme on another host of the preview' => ['//other.example/search'],
        ];
    }

    /**
     * A form sent via HTTP GET replaces the query of its action, so the token travels in a
     * hidden field to the hosts of the preview, where it keeps the page in the frame.
     */
    #[DataProvider('previewTokenIsAddedToFormsSentViaGetToHostsOfThePreviewDataProvider')]
    #[Test]
    public function previewTokenIsAddedToFormsSentViaGetToHostsOfThePreview(string $action): void
    {
        $request = $this->createRequest(true);
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form actionUri="' . $action . '" method="get" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        self::assertStringContainsString('<input type="hidden" name="__t3ct" value="preview-jwt" >', new TemplateView($context)->render());
    }

    public static function previewTokenIsNotAddedToFormsSentViaGetToOtherHostsDataProvider(): array
    {
        return [
            'external search service' => ['https://search.outside.example/'],
            'address relative to the scheme on another host' => ['//outside.example/search'],
            'host of the preview with user information' => ['https://editor@site.example/search'],
        ];
    }

    /**
     * A form sent via HTTP GET to another host, such as an external search or newsletter
     * service, never carries the token: in a new window, or in a browser that cannot keep
     * the navigation in the frame, the token would reach that host.
     */
    #[DataProvider('previewTokenIsNotAddedToFormsSentViaGetToOtherHostsDataProvider')]
    #[Test]
    public function previewTokenIsNotAddedToFormsSentViaGetToOtherHosts(string $action): void
    {
        $request = $this->createRequest(true);
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form actionUri="' . $action . '" method="get" target="_blank" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        self::assertStringNotContainsString('__t3ct', new TemplateView($context)->render());
    }

    public static function previewTokenIsNotAddedDataProvider(): array
    {
        return [
            'post in preview' => ['post', true],
            'get without preview' => ['get', false],
        ];
    }

    #[DataProvider('previewTokenIsNotAddedDataProvider')]
    #[Test]
    public function previewTokenIsNotAdded(string $method, bool $preview): void
    {
        $request = $this->createRequest($preview);
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:form actionUri="/search" method="' . $method . '" />');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        self::assertStringNotContainsString('__t3ct', new TemplateView($context)->render());
    }

    private function createRequest(bool $preview = false): ServerRequestInterface
    {
        $frontendTypoScript = new FrontendTypoScript(new RootNode(), [], [], []);
        $frontendTypoScript->setSetupTree(new RootNode());
        $frontendTypoScript->setSetupArray([]);
        $frontendTypoScript->setConfigArray([]);
        $contentObject = $this->get(ContentObjectRenderer::class);
        $serverRequest = new ServerRequest($preview ? 'https://site.example/page' : null)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('frontend.typoscript', $frontendTypoScript)
            ->withAttribute('extbase', new ExtbaseRequestParameters())
            ->withAttribute('currentContentObject', $contentObject);
        if ($preview) {
            $profile = new PreviewProfile(0, false, false, 0, []);
            $serverRequest = $serverRequest
                ->withAttribute(
                    PreviewAccess::ATTRIBUTE,
                    new PreviewToken('preview-jwt', $profile, 'https://backend.example.com', time() + 60, 1)
                )
                ->withAttribute(PreviewAccess::SCOPE_ATTRIBUTE, new PreviewScope([
                    ['site' => 'main', 'host' => 'site.example', 'origin' => 'https://site.example'],
                    ['site' => 'other', 'host' => 'other.example', 'origin' => 'https://other.example'],
                ]));
        }
        return new Request($serverRequest);
    }
}
