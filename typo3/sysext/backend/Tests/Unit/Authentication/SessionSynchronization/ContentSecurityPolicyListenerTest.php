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

namespace TYPO3\CMS\Backend\Tests\Unit\Authentication\SessionSynchronization;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\ContentSecurityPolicyListener;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\OriginResolver;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[BackupGlobals(true)]
final class ContentSecurityPolicyListenerTest extends UnitTestCase
{
    #[Test]
    public function mainBackendMayFrameOnlyExactSynchronizationPathsWithoutChangingOtherDirectives(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';
        $request = $this->createRequest('main');
        $policy = new Policy(SourceKeyword::self)
            ->set(Directive::FrameAncestors, SourceKeyword::none)
            ->set(Directive::ScriptSrc, SourceKeyword::self);
        $previousMutations = new MutationCollection(new Mutation(MutationMode::Set, Directive::FrameAncestors, SourceKeyword::none));
        $event = new PolicyMutatedEvent(Scope::backend(), $request, $policy, $policy, $previousMutations);

        ($this->createSubject())($event);

        $updated = $event->getCurrentPolicy();
        self::assertEquals([
            SourceKeyword::self,
            new UriValue('https://current.example.org/installation/admin/session/synchronize/bridge'),
            new UriValue('https://target.example.org/installation/admin/session/synchronize/bridge'),
        ], $updated->get(Directive::FrameSrc)->sources);
        self::assertFalse($updated->has(Directive::FormAction));
        self::assertSame($policy->get(Directive::FrameAncestors), $updated->get(Directive::FrameAncestors));
        self::assertSame($policy->get(Directive::ScriptSrc), $updated->get(Directive::ScriptSrc));
        self::assertSame($policy->get(Directive::DefaultSrc), $updated->get(Directive::DefaultSrc));
        self::assertSame($previousMutations, $event->getMutationCollections()[0]);
        self::assertCount(2, $event->getMutationCollections());
    }

    #[Test]
    public function explicitlyRestrictedFormsAreExtendedOnlyWithSynchronizationPaths(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';
        $existingFormTarget = new UriValue('https://forms.example.org/submit');
        $policy = new Policy(SourceKeyword::self)
            ->set(Directive::FrameSrc, SourceKeyword::self)
            ->set(Directive::FormAction, SourceKeyword::self, $existingFormTarget);
        $event = new PolicyMutatedEvent(Scope::backend(), $this->createRequest('main'), $policy, $policy);

        ($this->createSubject())($event);

        self::assertEquals([
            SourceKeyword::self,
            $existingFormTarget,
            new UriValue('https://current.example.org/installation/admin/session/synchronize/bridge'),
            new UriValue('https://target.example.org/installation/admin/session/synchronize/bridge'),
        ], $event->getCurrentPolicy()->get(Directive::FormAction)->sources);
        self::assertCount(2, $event->getMutationCollections()[0]->mutations);
    }

    public static function unrelatedRequestsDataProvider(): iterable
    {
        yield 'frontend scope even with main route' => ['frontend', 'main'];
        yield 'login route' => ['backend', 'login'];
        yield 'other backend route' => ['backend', 'record_edit'];
        yield 'missing route' => ['backend', null];
        yield 'missing request' => ['backend', false];
    }

    #[Test]
    #[DataProvider('unrelatedRequestsDataProvider')]
    public function unrelatedPoliciesRemainUnchanged(string $scope, string|false|null $route): void
    {
        $request = $route === false ? null : $this->createRequest($route);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects($this->never())->method('getAllSites');
        $subject = new ContentSecurityPolicyListener(new OriginResolver($siteFinder, new BackendEntryPointResolver()));
        $policy = new Policy(SourceKeyword::self)->set(Directive::FrameSrc, SourceKeyword::self);
        $event = new PolicyMutatedEvent(Scope::from($scope), $request, $policy, $policy);

        $subject($event);

        self::assertSame($policy, $event->getCurrentPolicy());
        self::assertSame([], $event->getMutationCollections());
    }

    #[Test]
    public function noPolicyIsExtendedWhenNoSecureOriginsAreAvailable(): void
    {
        $request = new ServerRequest('http://current.example.org/typo3/main')
            ->withAttribute('route', new Route('/main', ['_identifier' => 'main']));
        $policy = new Policy(SourceKeyword::self);
        $event = new PolicyMutatedEvent(Scope::backend(), $request, $policy, $policy);

        ($this->createSubject())($event);

        self::assertSame($policy, $event->getCurrentPolicy());
        self::assertSame([], $event->getMutationCollections());
    }

    private function createRequest(?string $identifier): ServerRequest
    {
        $normalizedParams = self::createStub(NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn('https://current.example.org');
        $normalizedParams->method('getSitePath')->willReturn('/installation/');
        $request = new ServerRequest('https://current.example.org/installation/admin/main')
            ->withAttribute('normalizedParams', $normalizedParams);
        return $identifier === null ? $request : $request->withAttribute('route', new Route('/main', ['_identifier' => $identifier]));
    }

    private function createSubject(): ContentSecurityPolicyListener
    {
        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://target.example.org/content/'));
        $site->method('getAllLanguages')->willReturn([]);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);
        return new ContentSecurityPolicyListener(new OriginResolver($siteFinder, new BackendEntryPointResolver()));
    }
}
