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

namespace TYPO3\CMS\Backend\Tests\Functional\Preview;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Preview\PreviewAccessDeniedException;
use TYPO3\CMS\Backend\Preview\PreviewTokenIssuer;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Preview\PreviewGroupFilter;
use TYPO3\CMS\Core\Preview\PreviewProfile;
use TYPO3\CMS\Core\Preview\PreviewTokenService;
use TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreviewTokenIssuerTest extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8'],
    ];

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups_with_editor.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_with_editor.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['workspace_perms' => 1], ['uid' => 9]);
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_groups')->insert('fe_groups', ['uid' => 5, 'pid' => 1, 'title' => 'Members']);
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_groups')->insert('fe_groups', ['uid' => 6, 'pid' => 7, 'title' => 'Other members']);
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
            $this->buildLanguageConfiguration('DE', '/de/'),
        ]);
        $this->writeSiteConfiguration('second', $this->buildSiteConfiguration(7, 'https://second.example:8443/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
    }

    #[Test]
    public function adminGetsTokenForAllSites(): void
    {
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/de/?a=1&ADMCMD_simTime=1796083200&ADMCMD_simUser=5'), 2);

        self::assertSame(['first.example', 'second.example:8443'], $preview->scope->getHosts());
        self::assertSame(['https://first.example', 'https://second.example:8443'], $preview->scope->getOrigins());
        self::assertSame('https://backend.example', $preview->token->backendOrigin);
        self::assertTrue($preview->scope->includesSite('first'));
        self::assertTrue($preview->scope->includesSite('second'));
        self::assertSame(1, $preview->token->userId);
        self::assertSame(1796083200, $preview->token->profile->date);
        self::assertSame([5], $preview->token->profile->groups);
        self::assertSame('https://first.example/de/?a=1&__t3ct=' . $preview->token->jwt, (string)$preview->uri);
        $verified = $this->get(PreviewTokenService::class)->verify($preview->token->jwt, self::USER_AGENT);
        self::assertEquals($preview->token->profile, $verified->profile);
        self::assertSame($preview->token->userId, $verified->userId);
    }

    #[Test]
    public function editorGetsTokenForSitesOfWebMountsOnly(): void
    {
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        self::assertSame(['first.example'], $preview->scope->getHosts());
        self::assertTrue($preview->scope->includesSite('first'));
        self::assertFalse($preview->scope->includesSite('second'));
        self::assertSame(['first.example'], $this->get(PreviewTokenIssuer::class)->getFrameSettings($preview, 2)['hosts']);
        self::assertSame(9, $preview->token->userId);
    }

    #[Test]
    public function editorGetsNoTokenForAddressOfAnotherSite(): void
    {
        $user = $this->setUpBackendUser(9);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1793265780);
        $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://second.example:8443/'), 0);
    }

    #[Test]
    public function editorGetsNoTokenForPageOutsideWebMounts(): void
    {
        $user = $this->setUpBackendUser(9);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1793265778);
        $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/'), 7);
    }

    #[Test]
    public function groupsOfThePageNeedNoReadAccess(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['fe_group' => '6'], ['uid' => 2]);
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simUser=6'), 2);

        self::assertSame([6], $preview->token->profile->groups);
    }

    #[Test]
    public function groupsExtendedToSubpagesNeedNoReadAccess(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['fe_group' => '6', 'extendToSubpages' => 1], ['uid' => 2]);
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simUser=6'), 3);

        self::assertSame([6], $preview->token->profile->groups);
    }

    #[Test]
    public function editorSimulatesReadableGroups(): void
    {
        $this->allowEditorToReadFrontendUserGroups();
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simTime=1796083200&ADMCMD_simUser=5'), 2);

        self::assertSame(1796083200, $preview->token->profile->date);
        self::assertSame([5], $preview->token->profile->groups);
    }

    #[Test]
    public function groupsOutsideWebMountsAreNotSimulated(): void
    {
        $this->allowEditorToReadFrontendUserGroups();
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simUser=5,6'), 2);

        self::assertSame([5], $preview->token->profile->groups);
    }

    #[Test]
    public function groupsAreNotSimulatedWithoutReadAccessToTheTable(): void
    {
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simUser=5'), 2);

        self::assertSame([], $preview->token->profile->groups);
    }

    #[Test]
    public function hiddenPagesAndContentAreLeftToTheRulesOfABackendLoginByDefault(): void
    {
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        self::assertFalse($preview->token->profile->hiddenPages);
        self::assertFalse($preview->token->profile->hiddenContent);
    }

    #[Test]
    public function renewalKeepsHiddenOfThePreviousToken(): void
    {
        $user = $this->setUpBackendUser(9);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $this->get(PreviewTokenService::class)->sign(new PreviewProfile(0, true, false, 0, []), 'https://backend.example', self::USER_AGENT, 9);

        $renewed = $issuer->renew($this->createRequest(), $user, $previous->jwt, new Uri('https://first.example/de/'), 3);

        self::assertTrue($renewed->token->profile->hiddenPages);
        self::assertFalse($renewed->token->profile->hiddenContent);
    }

    #[Test]
    public function readableGroupsAreTheGroupsTheEditorMayRead(): void
    {
        $groupFilter = $this->get(PreviewGroupFilter::class);
        self::assertSame([5 => 'Members', 6 => 'Other members'], $groupFilter->getReadableGroups($this->setUpBackendUser(1)));
        self::assertSame([], $groupFilter->getReadableGroups($this->setUpBackendUser(9)));

        $this->allowEditorToReadFrontendUserGroups();
        self::assertSame([5 => 'Members'], $groupFilter->getReadableGroups($this->setUpBackendUser(9)));
    }

    #[Test]
    public function deletedGroupIsNotSimulated(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('fe_groups')->update('fe_groups', ['deleted' => 1], ['uid' => 6]);
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simUser=5,6'), 2);

        self::assertSame([5], $preview->token->profile->groups);
    }

    #[Test]
    public function negativeSimulatedDateMeansTheCurrentTime(): void
    {
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simTime=-5'), 2);

        self::assertSame(0, $preview->token->profile->date);
    }

    #[Test]
    public function specialGroupValuesAreNotSimulated(): void
    {
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue(
            $this->createRequest(),
            $user,
            new Uri('https://first.example/?ADMCMD_simUser=-1,0,5'),
            2
        );

        self::assertSame([5], $preview->token->profile->groups);
    }

    #[Test]
    public function workspaceOnlyEditorGetsNoTokenForTheLiveWorkspace(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/sys_workspace.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')
            ->update('be_users', ['workspace_perms' => 0, 'workspace_id' => 1], ['uid' => 9]);
        $user = $this->setUpBackendUser(9);
        self::assertSame(1, $user->workspace);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1793265775);
        $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2, 0);
    }

    #[Test]
    public function workspaceOnlyEditorGetsVisitorTokenForTheLivePage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/sys_workspace.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')
            ->update('be_users', ['workspace_perms' => 0, 'workspace_id' => 1], ['uid' => 9]);
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issueForVisitor($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simUser=5'), 2);

        self::assertNull($preview->token->userId);
        self::assertTrue($preview->token->profile->isVisitorView());
        self::assertSame([], $preview->token->profile->groups);
        self::assertSame('https://first.example/?__t3ct=' . $preview->token->jwt, (string)$preview->uri);
    }

    #[Test]
    public function visitorTokenNeedsAccessToThePage(): void
    {
        $user = $this->setUpBackendUser(9);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1793265780);
        $this->get(PreviewTokenIssuer::class)->issueForVisitor($this->createRequest(), $user, new Uri('https://second.example:8443/'), 0);
    }

    #[Test]
    public function renewalOfAVisitorTokenSignsAVisitorToken(): void
    {
        $user = $this->setUpBackendUser(9);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issueForVisitor($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        $renewed = $issuer->renew($this->createRequest(), $user, $previous->token->jwt, new Uri('https://first.example/de/'), 3);

        self::assertNull($renewed->token->userId);
        self::assertTrue($renewed->token->profile->isVisitorView());
    }

    #[Test]
    public function visitorTokenIsValidOnTheSitesOfTheEditorWhoOpenedIt(): void
    {
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issueForVisitor($this->createRequest(), $user, new Uri('https://first.example/de/'), 2);

        self::assertNull($preview->token->userId);
        self::assertTrue($preview->token->visitor);
        self::assertSame(1, $preview->token->editorId);
        self::assertSame(['first.example', 'second.example:8443'], $preview->scope->getHosts());
    }

    #[Test]
    public function renewalOfAVisitorTokenForAnotherSiteSignsAVisitorToken(): void
    {
        $user = $this->setUpBackendUser(1);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issueForVisitor($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        $renewed = $issuer->renew($this->createRequest(), $user, $previous->token->jwt, new Uri('https://second.example:8443/'), 2);

        self::assertNull($renewed->token->userId);
        self::assertTrue($renewed->token->visitor);
        self::assertSame(1, $renewed->token->editorId);
        self::assertSame('https://second.example:8443/?__t3ct=' . $renewed->token->jwt, (string)$renewed->uri);
    }

    #[Test]
    public function renewalResolvesTheHostsOfThePreviewAgain(): void
    {
        $user = $this->setUpBackendUser(9);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['db_mountpoints' => '1,7'], ['uid' => 9]);

        $renewed = $issuer->renew($this->createRequest(), $this->setUpBackendUser(9), $previous->token->jwt, new Uri('https://first.example/'), 2);

        self::assertSame(['first.example'], $previous->scope->getHosts());
        self::assertSame(['first.example', 'second.example:8443'], $renewed->scope->getHosts());
        self::assertSame(['first.example', 'second.example:8443'], $issuer->getFrameSettings($renewed, 2)['hosts']);
    }

    #[Test]
    public function tokenLengthDoesNotDependOnTheNumberOfSites(): void
    {
        $user = $this->setUpBackendUser(1);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $before = $issuer->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);
        for ($site = 1000; $site < 1040; $site++) {
            $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => $site, 'pid' => 0, 'title' => 'Site ' . $site, 'is_siteroot' => 1]);
            $this->writeSiteConfiguration('site-' . $site, $this->buildSiteConfiguration($site, 'https://site-' . $site . '.example/'), [
                $this->buildDefaultLanguageConfiguration('EN', '/'),
                $this->buildLanguageConfiguration('DE', 'https://site-' . $site . '.de/'),
            ]);
        }

        $after = $issuer->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        self::assertCount(82, $after->scope->getHosts());
        self::assertSame(strlen($before->token->jwt), strlen($after->token->jwt));
        self::assertLessThan(600, strlen($after->token->jwt));
    }

    #[Test]
    public function renewalKeepsSimulationOfThePreviousToken(): void
    {
        $this->allowEditorToReadFrontendUserGroups();
        $user = $this->setUpBackendUser(9);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simTime=1796083200&ADMCMD_simUser=5'), 2);

        $renewed = $issuer->renew($this->createRequest(), $user, $previous->token->jwt, new Uri('https://first.example/de/'), 3);

        self::assertSame(1796083200, $renewed->token->profile->date);
        self::assertSame([5], $renewed->token->profile->groups);
        self::assertSame('https://first.example/de/?__t3ct=' . $renewed->token->jwt, (string)$renewed->uri);
    }

    #[Test]
    public function renewalChecksTheGroupsAgain(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['fe_group' => '6'], ['uid' => 2]);
        $user = $this->setUpBackendUser(9);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issue($this->createRequest(), $user, new Uri('https://first.example/?ADMCMD_simUser=6'), 2);
        self::assertSame([6], $previous->token->profile->groups);

        // Another page does not name the group, and the editor may not read it
        $renewed = $issuer->renew($this->createRequest(), $user, $previous->token->jwt, new Uri('https://first.example/de/'), 3);
        self::assertSame([], $renewed->token->profile->groups);

        // The page that names the group gets it again
        $renewed = $issuer->renew($this->createRequest(), $user, $previous->token->jwt, new Uri('https://first.example/'), 2);
        self::assertSame([6], $renewed->token->profile->groups);
    }

    #[Test]
    public function renewalChecksRightsAgain(): void
    {
        $user = $this->setUpBackendUser(9);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1793265778);
        $issuer->renew($this->createRequest(), $user, $previous->token->jwt, new Uri('https://first.example/'), 7);
    }

    /**
     * The page around the frame renews the token of the editor who opened it. Another
     * editor logged in to the same browser in the meantime gets no token from the
     * simulation of the previous one; the preview page opens again for this editor.
     */
    #[Test]
    public function renewalRefusesTheTokenOfAnotherEditor(): void
    {
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issue($this->createRequest(), $this->setUpBackendUser(1), new Uri('https://first.example/'), 2);
        $editor = $this->setUpBackendUser(9);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1794491403);
        $issuer->renew($this->createRequest(), $editor, $previous->token->jwt, new Uri('https://first.example/'), 2);
    }

    /**
     * The same holds for the visitor token of the live frame next to a workspace preview.
     */
    #[Test]
    public function renewalRefusesTheVisitorTokenOfAnotherEditor(): void
    {
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issueForVisitor($this->createRequest(), $this->setUpBackendUser(1), new Uri('https://first.example/'), 2);
        $editor = $this->setUpBackendUser(9);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1794491403);
        $issuer->renew($this->createRequest(), $editor, $previous->token->jwt, new Uri('https://first.example/'), 2);
    }

    #[Test]
    public function renewalRefusesTokenOfAnotherBrowser(): void
    {
        $user = $this->setUpBackendUser(9);
        $issuer = $this->get(PreviewTokenIssuer::class);
        $previous = $issuer->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1793276924);
        $issuer->renew($this->createRequest()->withHeader('User-Agent', 'curl/8.0'), $user, $previous->token->jwt, new Uri('https://first.example/'), 2);
    }

    #[Test]
    public function editorGetsNoTokenForInaccessibleWorkspace(): void
    {
        $user = $this->setUpBackendUser(9);

        $this->expectException(PreviewAccessDeniedException::class);
        $this->expectExceptionCode(1793265775);
        $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2, 42);
    }

    #[Test]
    public function hostsOfLanguagesWithoutAccessAreLeftOut(): void
    {
        $this->writeSiteConfiguration('first', $this->buildSiteConfiguration(1, 'https://first.example/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
            $this->buildLanguageConfiguration('DE', 'https://first.de/'),
        ]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['allowed_languages' => '0'], ['uid' => 9]);
        $user = $this->setUpBackendUser(9);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://first.example/'), 2);

        self::assertSame(['first.example'], $preview->scope->getHosts());
        self::assertSame(['first.example', 'first.de', 'second.example:8443'], $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $this->setUpBackendUser(1), new Uri('https://first.example/'), 2)->scope->getHosts());
    }

    #[Test]
    public function siteWithoutHostIsPreviewedOnTheBackendHost(): void
    {
        $this->writeSiteConfiguration('second', $this->buildSiteConfiguration(7, '/second/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('/second/'), 7);

        self::assertSame(['first.example', 'backend.example'], $preview->scope->getHosts());
        self::assertSame(['https://first.example', 'https://backend.example'], $preview->scope->getOrigins());
        self::assertSame('https://backend.example/second/?__t3ct=' . $preview->token->jwt, (string)$preview->uri);
    }

    #[Test]
    public function plainFrameSettingsResolveARelativeAddressAgainstTheBackend(): void
    {
        $settings = $this->get(PreviewTokenIssuer::class)->getPlainFrameSettings($this->createRequest(), new Uri('/headless/page?a=1&__t3ct=old'), 2);

        self::assertSame('https://backend.example/headless/page?a=1', $settings['url']);
        self::assertSame([], $settings['hosts']);
        self::assertSame('', $settings['renewUrl']);
    }

    #[Test]
    public function legacySimulationParametersMoveIntoTheToken(): void
    {
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue(
            $this->createRequest(),
            $user,
            new Uri('https://first.example/page?ADMCMD_simTime=1796083200&ADMCMD_simUser=5&ADMCMD_prev=IGNORE&x=1'),
            2
        );

        self::assertSame(1796083200, $preview->token->profile->date);
        self::assertSame([5], $preview->token->profile->groups);
        self::assertSame('https://first.example/page?x=1&__t3ct=' . $preview->token->jwt, (string)$preview->uri);
    }

    /**
     * The frontend address keeps its query as it is written: only the simulation of the
     * legacy parameters moves into the token. Repeated names, flags, dots in names and
     * encoded values reach the frame unchanged, as signed addresses compare them.
     */
    #[Test]
    public function frontendAddressKeepsTheBytesOfItsQuery(): void
    {
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue(
            $this->createRequest(),
            $user,
            new Uri('https://first.example/page?utm.source=x&ADMCMD_simTime=1796083200&id=1&id=2&flag&q=a+b&ADMCMD_simUser=5&sig=ab%2bcd&ADMCMD_prev=IGNORE&x'),
            2
        );

        self::assertSame(1796083200, $preview->token->profile->date);
        self::assertSame([5], $preview->token->profile->groups);
        self::assertSame('https://first.example/page?utm.source=x&id=1&id=2&flag&q=a+b&sig=ab%2bcd&x&__t3ct=' . $preview->token->jwt, (string)$preview->uri);
    }

    /**
     * The base of a site may name an internationalized domain name as editors write it,
     * while browsers request and frame the ASCII form of the host. The hosts and origins
     * of the preview, which the frame-src directive and the navigation script use, are
     * the ASCII form.
     */
    #[Test]
    public function hostsWithInternationalizedDomainNamesAreListedInTheirAsciiForm(): void
    {
        $this->writeSiteConfiguration('second', $this->buildSiteConfiguration(7, 'https://Bücher.example:8443/'), [
            $this->buildDefaultLanguageConfiguration('EN', '/'),
        ]);
        $user = $this->setUpBackendUser(1);

        $preview = $this->get(PreviewTokenIssuer::class)->issue($this->createRequest(), $user, new Uri('https://bücher.example:8443/'), 7);

        self::assertSame(['first.example', 'xn--bcher-kva.example:8443'], $preview->scope->getHosts());
        self::assertSame(['https://first.example', 'https://xn--bcher-kva.example:8443'], $preview->scope->getOrigins());
        self::assertStringStartsWith('https://bücher.example:8443/?__t3ct=', (string)$preview->uri);
    }

    private function allowEditorToReadFrontendUserGroups(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')
            ->update('be_groups', ['tables_select' => 'pages,tt_content,fe_groups'], ['uid' => 9]);
    }

    private function createRequest(): ServerRequest
    {
        return new ServerRequest('https://backend.example/typo3/preview', 'GET', null, ['User-Agent' => self::USER_AGENT]);
    }
}
