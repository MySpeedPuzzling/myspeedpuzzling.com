<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The organization page (docs/features/organizations/README.md "Organization page"): header, About, "Coming up", "What
 * we run", "Past"; its team also sees drafts, tagged; a draft organization only its team; one waiting for approval is
 * reachable without the star. No public team list.
 */
final class OrganizationPageTest extends WebTestCase
{
    private const string RIVERBEND = '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;

    public function testTheGuestSeesTheHeaderAndTheAbout(): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, self::RIVERBEND);

        self::assertSelectorTextContains('h1[data-organization-id="' . OrganizationFixture::ORGANIZATION_RIVERBEND . '"]', OrganizationFixture::ORGANIZATION_RIVERBEND_NAME);
        self::assertSelectorNotExists('meta[name="robots"][content="noindex, nofollow"]');

        $facts = $crawler->filter('.ev-org-header .ev-detail-facts')->text();
        self::assertStringContainsString('Association or federation', $facts);
        self::assertStringContainsString('Riverbend Valley', $facts);
        self::assertStringContainsString('United States', $facts);
        self::assertStringContainsString('RJA', $facts);
        self::assertCount(1, $crawler->filter('.ev-org-header .fi-us'), 'the flag of its country');

        // Website and the social links: an icon link each, named by its platform
        self::assertSame('https://riverbend-jigsaw.example', $crawler->filter('[data-org-website]')->attr('href'));
        $instagram = $crawler->filter('[data-org-social-link="instagram"]');
        self::assertSame('Instagram', $instagram->attr('aria-label'));
        self::assertSame('https://www.instagram.com/riverbendjigsaw', $instagram->attr('href'));
        self::assertCount(1, $instagram->filter('i.bi-instagram[aria-hidden="true"]'));
        self::assertSame('Discord', $crawler->filter('[data-org-social-link="discord"]')->attr('aria-label'));

        // A guest gets the labelled follow star (a sign-in link), no ⋯
        self::assertCount(1, $crawler->filter('.ev-org-header .ev-star-labelled'));
        self::assertCount(0, $crawler->filter('.ev-org-header .ev-manage'));

        self::assertStringContainsString('The puzzle association of Riverbend Valley.', $crawler->filter('[data-org-about]')->text());
        self::assertCount(1, $crawler->filter('[data-org-about] br'), 'line breaks kept');

        // No team list on the public page (D19)
        self::assertStringNotContainsString('data-org-team', (string) $browser->getResponse()->getContent());
    }

    public function testComingUpListsTheRowsOfItsSeriesAndEventsWithTheirTags(): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, self::RIVERBEND);
        $coming = $crawler->filter('[data-org-coming]');

        $lanternRow = $coming->filter('[data-series-edition="' . OrganizationFixture::EDITION_LANTERN_1 . '"]');
        self::assertCount(1, $lanternRow);
        self::assertStringContainsString(OrganizationFixture::SERIES_LANTERN_NIGHTS_NAME, $lanternRow->filter('.ev-row-name')->text());
        self::assertStringContainsString('Lantern Night One', $lanternRow->filter('.ev-row-edition')->text());
        $tags = $lanternRow->filter('.ev-tag')->each(static fn (Crawler $tag): string => $tag->text());
        self::assertContains('Recurring', $tags);
        self::assertContains('Who can enter: 18+', $tags);
        self::assertCount(0, $lanternRow->filter('.ev-star'), 'no star on a row');

        // The one-time event and the online edition are rows too; the past edition is not
        self::assertCount(1, $coming->filter('[data-series-edition="' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN . '"]'));
        self::assertCount(1, $coming->filter('[data-series-edition="' . OrganizationFixture::EDITION_VIRTUAL_NEXT . '"]'));
        self::assertCount(0, $coming->filter('[data-series-edition="' . OrganizationFixture::EDITION_VIRTUAL_PAST . '"]'));
        self::assertSame('4', $coming->filter('#org-coming-title .ev-section-n')->text());

        // Their draft edition is the team's only
        self::assertCount(0, $crawler->filter('[data-series-edition="' . OrganizationFixture::EDITION_LANTERN_DRAFT . '"]'));
        self::assertStringNotContainsString(OrganizationFixture::EDITION_LANTERN_DRAFT_NAME, (string) $browser->getResponse()->getContent());
    }

    public function testWhatWeRunHasACardPerSeriesAndTheComingOneTimeEvents(): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, self::RIVERBEND);

        $lantern = $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '"]');
        self::assertCount(1, $lantern);
        self::assertSame('/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, $lantern->filter('.ev-org-card-name')->attr('href'));
        self::assertStringContainsString('Second Thursday of the month, 7:30 pm', $lantern->filter('[data-org-schedule]')->text());
        self::assertStringContainsString('18+', $lantern->filter('[data-org-eligibility]')->text());
        self::assertStringContainsString('2 editions', $lantern->text(), 'the draft edition is not counted for a guest');
        self::assertStringStartsWith('Next:', trim($lantern->filter('[data-org-next="next"]')->text()));
        self::assertCount(1, $lantern->filter('.ev-star'), 'a follow star per series');

        $virtual = $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL . '"]');
        self::assertStringContainsString('Online', $virtual->text());
        self::assertStringContainsString('Fourth Friday of the month, 8 pm', $virtual->text());

        // Ordered by their next date: the Lantern nights (+22 days) before the virtual contest (+30 days)
        self::assertSame(
            [OrganizationFixture::SERIES_LANTERN_NIGHTS, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL],
            $crawler->filter('[data-org-series]')->each(static fn (Crawler $card): null|string => $card->attr('data-org-series')),
        );

        $open = $crawler->filter('[data-org-event="' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN . '"]');
        self::assertCount(1, $open);
        self::assertSame('/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG, $open->filter('.ev-org-card-name')->attr('href'));
        self::assertStringContainsString('Residents of Riverbend Valley', $open->text());
        self::assertCount(1, $open->filter('.ev-star'));
    }

    public function testThePastIsByYear(): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, self::RIVERBEND);
        $past = $crawler->filter('[data-org-past]');

        self::assertCount(1, $past);
        self::assertSame('series-archive', $past->attr('data-controller'));
        $line = $past->filter('a[href="/en/series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG . '/virtual-contest-1"]');
        self::assertCount(1, $line);
        self::assertStringContainsString('Virtual Contest 1', $line->text());
        self::assertCount(1, $past->filter('.ev-series-year[data-year="' . ((int) date('Y') - 1) . '"]'));
    }

    public function testItsTeamSeesTheDraftEditionTaggedAndTheMenu(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->page($browser, self::RIVERBEND);

        $draftRow = $crawler->filter('[data-org-coming] [data-series-edition="' . OrganizationFixture::EDITION_LANTERN_DRAFT . '"]');
        self::assertCount(1, $draftRow);
        self::assertSame(['Draft'], $draftRow->filter('.ev-tag-draft')->each(static fn (Crawler $tag): string => $tag->text()));
        self::assertCount(1, $draftRow->filter('.ev-manage'), 'its ⋯');

        self::assertCount(1, $crawler->filter('.ev-org-header .ev-manage'), 'the organization ⋯ for its team');
        self::assertCount(1, $crawler->filter('[data-controller="event-manage-menu"]'), 'the menu host');
        self::assertStringContainsString('3 editions', $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '"]')->text());
    }

    public function testTheMaintainerIsOnTheTeamToo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->page($browser, self::RIVERBEND);

        self::assertCount(1, $crawler->filter('[data-series-edition="' . OrganizationFixture::EDITION_LANTERN_DRAFT . '"]'));
        self::assertCount(1, $crawler->filter('.ev-org-header .ev-manage'));
    }

    public function testAFollowerSeesTheirStarsPressed(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->page($browser, self::RIVERBEND);

        $star = $crawler->filter('.ev-org-header button.ev-star-labelled');
        self::assertSame('true', $star->attr('aria-pressed'));
        self::assertSame('organization:' . OrganizationFixture::ORGANIZATION_RIVERBEND, $star->attr('data-follow-target'));
        self::assertSame('Following', trim($star->text()));

        // PLAYER_REGULAR follows the Lantern nights, not the virtual contest
        self::assertSame('true', $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '"] .ev-star')->attr('aria-pressed'));
        self::assertSame('false', $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL . '"] .ev-star')->attr('aria-pressed'));

        // Not on its team: no draft edition, no ⋯
        self::assertCount(0, $crawler->filter('[data-series-edition="' . OrganizationFixture::EDITION_LANTERN_DRAFT . '"]'));
        self::assertCount(0, $crawler->filter('.ev-org-header .ev-manage'));
    }

    public function testADraftOrganizationIsOnlyForItsTeam(): void
    {
        $path = '/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG;
        $browser = self::createClient();

        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->page($browser, $path);
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertCount(0, $crawler->filter('.ev-org-header .ev-star'), 'a draft cannot be followed');
        // Its published series keeps its own state - listed with its star
        self::assertCount(1, $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS . '"] .ev-star'));
    }

    public function testAnOrganizationWaitingForApprovalIsReachableWithoutTheStar(): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, '/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG);

        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertCount(0, $crawler->filter('.ev-org-header .ev-star'));
        // Its series waits for approval too - a guest sees nothing of it
        self::assertCount(0, $crawler->filter('[data-org-series]'));
        self::assertCount(1, $crawler->filter('[data-org-nothing-planned]'));
        self::assertCount(0, $crawler->filter('[data-org-nothing-planned] a'), 'Add event is for its team');
    }

    public function testItsTeamSeesTheSeriesWaitingForApprovalTagged(): void
    {
        $browser = self::createClient();
        // Not the team: no word about its state
        self::assertCount(0, $this->page($browser, '/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG)->filter('[data-org-waiting]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->page($browser, '/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG);
        self::assertSame('Waiting for approval', $crawler->filter('.ev-org-header [data-org-waiting]')->text());

        $card = $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_MAPLE_PENDING . '"]');
        self::assertCount(1, $card);
        self::assertSame('Waiting for approval', $card->filter('.ev-tag-waiting_for_approval')->text());
        self::assertCount(0, $card->filter('.ev-star'), 'not public - no star');
        self::assertCount(1, $crawler->filter('[data-org-coming] [data-series-edition="' . OrganizationFixture::EDITION_MAPLE_PENDING_1 . '"]'));
    }

    public function testAnEmptyOrganizationOffersItsTeamToAddAnEvent(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->page($browser, '/en/organizations/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT_SLUG);

        $empty = $crawler->filter('[data-org-nothing-planned]');
        self::assertStringContainsString('Nothing planned yet.', $empty->text());
        self::assertStringContainsString('organization=' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, (string) $empty->filter('a')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-org-run]'));
        self::assertCount(0, $crawler->filter('[data-org-past]'));
    }

    public function testAnUnknownSlugIsNotFound(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/organizations/no-such-organization');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Canary: the draft edition shows to a guest once published by SQL, and is gone again as a draft - the page reads
     * the draft flag, not some other rule that happens to hide it
     */
    public function testTheDraftEditionIsHiddenOnlyByItsDraftFlag(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $selector = '[data-series-edition="' . OrganizationFixture::EDITION_LANTERN_DRAFT . '"]';

        $connection->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => OrganizationFixture::EDITION_LANTERN_DRAFT]);
        self::assertCount(1, $this->page($browser, self::RIVERBEND)->filter($selector), 'published: listed to a guest');

        $connection->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => OrganizationFixture::EDITION_LANTERN_DRAFT]);
        self::assertCount(0, $this->page($browser, self::RIVERBEND)->filter($selector), 'a draft: not listed');
    }

    /**
     * Canary: a draft series under the organization - its card and its editions show to a guest once published, and are
     * gone as a draft
     */
    public function testADraftSeriesUnderTheOrganizationIsHiddenOnlyByItsDraftFlag(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE competition_series SET organization_id = :organization, is_draft = false WHERE id = :id',
            ['organization' => OrganizationFixture::ORGANIZATION_RIVERBEND, 'id' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT],
        );

        $crawler = $this->page($browser, self::RIVERBEND);
        self::assertCount(1, $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT . '"]'));
        self::assertCount(1, $crawler->filter('[data-series-edition="' . OrganizationFixture::EDITION_QUIET_PINES_1 . '"]'));

        $connection->executeStatement('UPDATE competition_series SET is_draft = true WHERE id = :id', ['id' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT]);

        $crawler = $this->page($browser, self::RIVERBEND);
        self::assertCount(0, $crawler->filter('[data-org-series="' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT . '"]'));
        self::assertCount(0, $crawler->filter('[data-series-edition="' . OrganizationFixture::EDITION_QUIET_PINES_1 . '"]'));
    }

    public function testPastLinesCarryTheDraftAndWaitingTagsForTheTeam(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);

        foreach ([OrganizationFixture::EDITION_LANTERN_DRAFT, OrganizationFixture::EDITION_MAPLE_PENDING_1] as $competitionId) {
            $connection->executeStatement(
                'UPDATE competition SET date_from = CURRENT_DATE - 40, date_to = CURRENT_DATE - 40 WHERE id = :id',
                ['id' => $competitionId],
            );
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $past = $this->page($browser, self::RIVERBEND)->filter('[data-org-past]');
        $draftLine = $past->filter('.ev-line:contains("' . OrganizationFixture::EDITION_LANTERN_DRAFT_NAME . '")');
        self::assertCount(1, $draftLine);
        self::assertSame('Draft', $draftLine->filter('[data-ev-state-tag]')->text());

        // The series page too
        $past = $this->page($browser, '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG)->filter('.ev-line:contains("' . OrganizationFixture::EDITION_LANTERN_DRAFT_NAME . '")');
        self::assertSame('Draft', $past->filter('.ev-tag-draft')->text());

        // Waiting for approval on the organization page (the series page leaves it out, as on its rows)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $past = $this->page($browser, '/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG)->filter('[data-org-past]');
        self::assertSame('Waiting for approval', $past->filter('.ev-line:contains("Maple Evening 1") [data-ev-state-tag]')->text());

        // Everybody else sees no draft at all - and no tag on the published lines
        $browser->getCookieJar()->clear();
        $past = $this->page($browser, self::RIVERBEND)->filter('[data-org-past]');
        self::assertCount(0, $past->filter('[data-ev-state-tag]'));
        self::assertStringNotContainsString(OrganizationFixture::EDITION_LANTERN_DRAFT_NAME, $past->text());
    }

    private function page(KernelBrowser $browser, string $path): Crawler
    {
        $crawler = $browser->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
