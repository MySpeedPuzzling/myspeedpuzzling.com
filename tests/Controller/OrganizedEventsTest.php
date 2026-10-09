<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "You organize" with organizations and drafts (docs/features/organizations/README.md "You organize", P9): the
 * viewer's organizations first, each with its series and one-time events under it, then everything else; the count is
 * the header button's (organizations + the items not under one of them).
 */
final class OrganizedEventsTest extends WebTestCase
{
    public function testTheOrganizationsComeFirstWithTheirSeriesAndEventsUnderThem(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();

        $topLevel = $this->topLevelIds($crawler);
        // The draft organization needs attention first, then the published one
        self::assertSame(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, $topLevel[0]);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $topLevel[1]);

        $riverbend = $this->item($crawler, OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertSame('organization', $riverbend->attr('data-organized-kind'));
        self::assertSame('/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $riverbend->filter('.ev-organized-name')->first()->attr('href'));
        self::assertSame('Organization · 2 series · 1 one-time event · United States', $riverbend->filter('.ev-organized-sub')->first()->text());
        // Approved and published: no badge of its own
        self::assertCount(0, $riverbend->filter('.ev-organized-top')->first()->filter('.ev-badge'));

        self::assertEqualsCanonicalizing([
            OrganizationFixture::SERIES_LANTERN_NIGHTS,
            OrganizationFixture::SERIES_RIVERBEND_VIRTUAL,
            OrganizationFixture::COMPETITION_RIVERBEND_OPEN,
        ], $this->nestedIds($riverbend));
        // Listed once - under its organization, not among the rest
        self::assertNotContains(OrganizationFixture::SERIES_LANTERN_NIGHTS, $topLevel);
        self::assertNotContains(OrganizationFixture::COMPETITION_RIVERBEND_OPEN, $topLevel);

        $harbor = $this->item($crawler, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT);
        self::assertSame('Draft', $harbor->filter('.ev-organized-top')->first()->filter('.ev-badge')->text());
        self::assertSame([OrganizationFixture::SERIES_HARBOR_CLUB_MEETS], $this->nestedIds($harbor));

        // The rest: drafts first (after anything rejected), each with its Draft badge
        $draftNight = $this->item($crawler, OrganizationFixture::COMPETITION_DRAFT_NIGHT);
        self::assertSame('Draft', $draftNight->filter('.ev-badge')->text());
        self::assertSame('Draft', $this->item($crawler, OrganizationFixture::SERIES_QUIET_PINES_DRAFT)->filter('.ev-badge')->first()->text());
        // Pending and a draft: Draft (it is submitted by publishing it)
        self::assertSame('Draft', $this->item($crawler, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT)->filter('.ev-badge')->text());

        $badges = array_map(
            fn (string $id): string => $this->item($crawler, $id)->filter('.ev-badge')->first()->text(),
            array_slice($topLevel, 2),
        );
        // Rejected, then Draft, then Waiting for approval, then the dated ones - never a step back
        $rank = ['Rejected' => 0, 'Draft' => 1, 'Waiting for approval' => 2, 'Live' => 3, 'Upcoming' => 4, 'Date not set' => 5, 'Past' => 6];
        $ranks = array_map(static fn (string $badge): int => $rank[$badge] ?? 99, $badges);
        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks);
        self::assertContains(1, $ranks);

        $this->assertTheCountIsTheHeaderButtons($browser, $crawler, count($topLevel));
    }

    public function testATeamMemberSeesTheOrganizationsOfTheTeamsAndTheirStates(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();

        // Draft, Waiting for approval, then the published one - nothing else is organised
        self::assertSame([
            OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT,
            OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            OrganizationFixture::ORGANIZATION_RIVERBEND,
        ], $this->topLevelIds($crawler));

        self::assertSame('Draft', $this->item($crawler, OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT)->filter('.ev-badge')->first()->text());
        $maple = $this->item($crawler, OrganizationFixture::ORGANIZATION_MAPLE_PENDING);
        self::assertSame('Waiting for approval', $maple->filter('.ev-badge')->first()->text());
        self::assertSame([OrganizationFixture::SERIES_MAPLE_PENDING], $this->nestedIds($maple));

        $this->assertTheCountIsTheHeaderButtons($browser, $crawler, 3);
    }

    public function testTheActionsOfAnOrganization(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/you-organize');

        // Riverbend's maintainer: edit, add an event under it, back to draft - deleting is for its creator (and it is
        // not empty)
        $riverbend = $this->ownActions($this->item($crawler, OrganizationFixture::ORGANIZATION_RIVERBEND));
        self::assertSame(['Edit organization', 'Add event', 'Unpublish…'], $this->labels($riverbend));
        $addEvent = (string) $riverbend->filter('a.ev-action')->eq(1)->attr('href');
        self::assertStringStartsWith('/en/add-event?', $addEvent);
        parse_str((string) parse_url($addEvent, PHP_URL_QUERY), $query);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $query['organization'] ?? null);
        self::assertSame('/en/you-organize', $query['return'] ?? null);
        self::assertSame('/en/unpublish-organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, $riverbend->filter('details[data-unpublish] form')->attr('action'));

        // Cedar: its creator's empty draft - Publish first, Delete last
        $cedar = $this->ownActions($this->item($crawler, OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));
        self::assertSame(['Publish', 'Edit organization', 'Add event', 'Delete organization'], $this->labels($cedar));
        $publish = $cedar->filter('button[data-publish]')->closest('form');
        self::assertNotNull($publish);
        self::assertSame('/en/publish-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, $publish->attr('action'));
        self::assertNotEmpty($publish->filter('input[name="_token"]')->attr('value'));
        self::assertSame('/en/you-organize', $publish->filter('input[name="return"]')->attr('value'));
        self::assertSame('/en/delete-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, $cedar->filter('details[data-delete] form')->attr('action'));
    }

    public function testPublishingFromThePageComesBackWithTheFlash(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/you-organize');
        $form = $this->ownActions($this->item($crawler, OrganizationFixture::COMPETITION_DRAFT_NIGHT))->filter('button[data-publish]')->form();

        $browser->submit($form);
        self::assertResponseRedirects('/en/you-organize');
        $crawler = $browser->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'Published');
        // Published: offered back to draft now
        $actions = $this->ownActions($this->item($crawler, OrganizationFixture::COMPETITION_DRAFT_NIGHT));
        self::assertCount(0, $actions->filter('button[data-publish]'));
        self::assertCount(1, $actions->filter('details[data-unpublish]'));
    }

    public function testAnItemThatCannotGoBackToDraftSaysWhyInsteadOfOfferingIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/you-organize');
        // The Results Cup has official results - the same rule as the ⋯ menu, read in the page's one statement
        $cup = $this->ownActions($this->item($crawler, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertCount(0, $cup->filter('details[data-unpublish]'));
        self::assertStringContainsString('it has official results', $cup->filter('[data-cannot-unpublish]')->text());

        // An event nothing holds is still offered
        $riverbendOpen = $this->ownActions($this->item($crawler, OrganizationFixture::COMPETITION_RIVERBEND_OPEN));
        self::assertCount(1, $riverbendOpen->filter('details[data-unpublish]'));
    }

    public function testThePageLinksTheOrganizationsAndTheAddForms(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/you-organize');

        self::assertStringStartsWith('/en/add-event?', (string) $crawler->filter('a[data-add-event]')->attr('href'));
        self::assertStringStartsWith('/en/add-organization?', (string) $crawler->filter('a[data-add-organization]')->attr('href'));
        self::assertSame('/en/organizations', $crawler->filter('a.ev-organized-directory')->attr('href'));
    }

    private function assertTheCountIsTheHeaderButtons(KernelBrowser $browser, Crawler $page, int $expected): void
    {
        self::assertSame((string) $expected, $page->filter('.ev-organized-total')->text());

        $events = $browser->request('GET', '/en/events');
        self::assertSame((string) $expected, $events->filter('.ev-organize-button .ev-organize-count')->text());
    }

    /**
     * @return list<string>
     */
    private function topLevelIds(Crawler $crawler): array
    {
        return $crawler->filter('.ev-organized-page > ul.ev-organized > li[data-organized-id]')->each(
            static fn (Crawler $item): string => (string) $item->attr('data-organized-id'),
        );
    }

    /**
     * @return list<string>
     */
    private function nestedIds(Crawler $organization): array
    {
        return $organization->filter('ul.ev-organized-nested > li[data-organized-id]')->each(
            static fn (Crawler $item): string => (string) $item->attr('data-organized-id'),
        );
    }

    /**
     * The item's own actions - not those of the items nested under it
     */
    private function ownActions(Crawler $item): Crawler
    {
        return $item->filter('ul.ev-actions')->first();
    }

    /**
     * @return list<string>
     */
    private function labels(Crawler $actions): array
    {
        return $actions->filter('li > a.ev-action, li > form > button.ev-action, li > details > summary')->each(
            static fn (Crawler $item): string => trim($item->text()),
        );
    }

    private function item(Crawler $crawler, string $id): Crawler
    {
        $item = $crawler->filter('[data-organized-id="' . $id . '"]');
        self::assertCount(1, $item, $id);

        return $item;
    }
}
