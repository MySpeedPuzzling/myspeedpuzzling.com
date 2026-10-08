<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The organisers' tools say "This is a draft…" on a draft - its own flag or its series' - instead of the approval
 * wording, which stays for an event waiting for approval (docs/features/organizations/README.md "Drafts").
 */
final class DraftOrganiserHintsTest extends WebTestCase
{
    private const string DRAFT_HINT = 'This is a draft - only you and your team can see it.';

    public function testThePageEditorsOfADraftSayItIsADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ([OrganizationFixture::COMPETITION_DRAFT_NIGHT, OrganizationFixture::EDITION_QUIET_PINES_1] as $competitionId) {
            $browser->request('GET', '/en/manage-event-page/' . $competitionId);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-page-sections-not-public]', self::DRAFT_HINT);
        }

        $browser->request('GET', '/en/manage-series-page/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-page-sections-not-public]', 'This series is a draft - only you and your team can see it.');
    }

    public function testThePageEditorOfAnEventWaitingForApprovalKeepsTheApprovalWording(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/manage-event-page/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-page-sections-not-public]', 'Visitors see these sections only once the event is approved.');
    }

    public function testTheRegistrationSettingsOfADraftSayItIsADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/manage-event-registration/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'This is a draft - only you and your team can see it. Puzzlers can register once you publish it.');
        self::assertSelectorTextNotContains('main', 'Puzzlers can register only once the event is approved');
    }

    public function testTheResultsDeskOfADraftSaysItIsADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/manage-round-results/' . OrganizationFixture::ROUND_DRAFT_NIGHT);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('The results page stays hidden and the players are told once you publish it.', html_entity_decode($content));
        self::assertStringNotContainsString('the players are told once the event is approved', html_entity_decode($content));
    }
}
