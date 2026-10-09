<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Query\GetAdminQueueCounts;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Query\GetUpcomingEventsCount;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A draft appears on no public surface (docs/features/organizations/README.md "Drafts") - and the read side has no
 * chokepoint, so nothing but a test notices a surface that forgot the rule. For every surface: with the item published
 * (`is_draft = false` by SQL) the surface must show it - or it proves nothing - and as a draft it must not. A guest and a
 * signed-in player who is not on the item's team (PLAYER_REGULAR) see the same; on the events page admins too.
 *
 * Items: a draft one-time event, a draft edition in a published series, an edition of a draft series (and the draft
 * series itself), a draft organization, a past draft event; a surface that shows an item only in another place (the
 * organization page, the past) gets it there by SQL first. **Add every new event-listing surface here.**
 * DraftVisibilityCoverageTest keeps every reader of the event tables decided; DraftPagesTest covers the item pages.
 */
final class DraftCanaryTest extends WebTestCase
{
    private const string COMPETITION = 'competition';
    private const string SERIES = 'competition_series';
    private const string ORGANIZATION = 'organization';

    private const string EVENTS = '/en/events';
    private const string LANTERN_SERIES_PAGE = '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG;
    private const string HARBOR_SERIES_PAGE = '/en/series/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_SLUG;
    private const string RIVERBEND_PAGE = '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;
    private const string PUZZLE_PAGE = '/en/puzzle/' . PuzzleFixture::PUZZLE_3000;
    private const string ADD_TIME = '/en/puzzle-add';
    private const string PICKER_EDITIONS = '/en/competition-picker/editions?q=';
    private const string PICKER_PREVIEW = '/en/competition-picker/series-preview?people=0&series=';
    private const string SITEMAP = '/sitemap-events.xml';
    // Replaced by last year (the clock's) - the past draft is dated in it
    private const string ARCHIVE = '/en/events/archive/{lastYear}';
    private const string QUIET_PINES_UNDER_RIVERBEND = "UPDATE competition_series SET organization_id = :riverbend WHERE id = '" . OrganizationFixture::SERIES_QUIET_PINES_DRAFT . "'";

    /**
     * @param self::COMPETITION|self::SERIES|self::ORGANIZATION $table
     * @param list<string> $setup SQL run first - puts the item where the surface shows it (`:riverbend` = its id)
     */
    #[DataProvider('provideSurfaces')]
    public function testADraftIsOnNoPublicSurface(string $table, string $id, string $url, null|string $viewer, string $needle, null|string $selector = null, array $setup = []): void
    {
        $browser = self::createClient();
        $url = str_replace('{lastYear}', (string) ((int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') - 1), $url);

        foreach ($setup as $statement) {
            self::getContainer()->get(Connection::class)->executeStatement($statement, str_contains($statement, ':riverbend') ? ['riverbend' => OrganizationFixture::ORGANIZATION_RIVERBEND] : []);
        }

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        $this->setDraft($table, $id, false);
        self::assertStringContainsString($needle, $this->surface($browser, $url, $selector), 'The surface does not show the item once it is published - it is no canary. Pick a surface/item that does.');

        $this->setDraft($table, $id, true);
        self::assertStringNotContainsString($needle, $this->surface($browser, $url, $selector), 'A draft is shown on a public surface.');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: null|string, 4: string, 5?: null|string, 6?: list<string>}>
     */
    public static function provideSurfaces(): iterable
    {
        $draftNight = [self::COMPETITION, OrganizationFixture::COMPETITION_DRAFT_NIGHT];
        $lanternDraft = [self::COMPETITION, OrganizationFixture::EDITION_LANTERN_DRAFT];
        $quietPines = [self::SERIES, OrganizationFixture::SERIES_QUIET_PINES_DRAFT];
        $harborClub = [self::ORGANIZATION, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT];
        $draftPast = [self::COMPETITION, OrganizationFixture::COMPETITION_DRAFT_PAST];

        // The events page: the agenda, the series directory and the search index embedded in the page - drafts nowhere,
        // the admins' view included
        foreach (['guest' => null, 'player' => PlayerFixture::PLAYER_REGULAR, 'admin' => PlayerFixture::PLAYER_ADMIN] as $who => $viewer) {
            yield "events page ({$who}) - draft one-time event" => [...$draftNight, self::EVENTS, $viewer, OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME];
            yield "events page ({$who}) - draft edition of a published series" => [...$lanternDraft, self::EVENTS, $viewer, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME];
            yield "events page ({$who}) - edition of a draft series" => [...$quietPines, self::EVENTS, $viewer, OrganizationFixture::EDITION_QUIET_PINES_1_NAME];
            yield "events page ({$who}) - draft series in the series directory" => [...$quietPines, self::EVENTS, $viewer, OrganizationFixture::SERIES_QUIET_PINES_DRAFT_NAME];
        }

        // Waiting for approval AND a draft: the admins' "Waiting for approval" rows never list it
        yield 'events page (admin) - draft waiting for approval' => [self::COMPETITION, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT, self::EVENTS, PlayerFixture::PLAYER_ADMIN, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT_NAME];

        yield 'calendar view (guest) - draft one-time event' => [...$draftNight, self::EVENTS . '?view=calendar', null, OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME];

        // "Your events": PLAYER_REGULAR follows the series - a row from before it went back to draft
        yield '"Your events" (player) - edition of a followed draft series' => [...$quietPines, self::EVENTS, PlayerFixture::PLAYER_REGULAR, OrganizationFixture::EDITION_QUIET_PINES_1_NAME, '[data-ev-section="your"]'];

        foreach (['guest' => null, 'player' => PlayerFixture::PLAYER_REGULAR] as $who => $viewer) {
            yield "archive year ({$who}) - past draft event" => [...$draftPast, self::ARCHIVE, $viewer, OrganizationFixture::COMPETITION_DRAFT_PAST_NAME];
            yield "series page ({$who}) - draft edition of the series" => [...$lanternDraft, self::LANTERN_SERIES_PAGE, $viewer, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME];
            yield "organizations directory ({$who}) - draft organization" => [...$harborClub, '/en/organizations', $viewer, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME];

            // The organization page: Coming up, What we run, Past - the draft series moved under Riverbend for it
            yield "organization page ({$who}) - Coming up: draft edition" => [...$lanternDraft, self::RIVERBEND_PAGE, $viewer, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME, '[data-org-coming]'];
            yield "organization page ({$who}) - Coming up: edition of a draft series" => [...$quietPines, self::RIVERBEND_PAGE, $viewer, OrganizationFixture::EDITION_QUIET_PINES_1_NAME, '[data-org-coming]', [self::QUIET_PINES_UNDER_RIVERBEND]];
            yield "organization page ({$who}) - What we run: draft series card" => [...$quietPines, self::RIVERBEND_PAGE, $viewer, OrganizationFixture::SERIES_QUIET_PINES_DRAFT_NAME, '[data-org-run]', [self::QUIET_PINES_UNDER_RIVERBEND]];
            yield "organization page ({$who}) - Past: draft edition" => [...$lanternDraft, self::RIVERBEND_PAGE, $viewer, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME, '[data-org-past]', [self::inThePast(OrganizationFixture::EDITION_LANTERN_DRAFT)]];
            yield "organization page ({$who}) - Past: edition of a draft series" => [...$quietPines, self::RIVERBEND_PAGE, $viewer, OrganizationFixture::EDITION_QUIET_PINES_1_NAME, '[data-org-past]', [self::QUIET_PINES_UNDER_RIVERBEND, self::inThePast(OrganizationFixture::EDITION_QUIET_PINES_1)]];

            // A published series of a draft organization: no "Organized by", no crumb
            yield "series page ({$who}) - \"Organized by\" a draft organization" => [...$harborClub, self::HARBOR_SERIES_PAGE, $viewer, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, '.ev-organized-by'];
            yield "series page ({$who}) - crumb of a draft organization" => [...$harborClub, self::HARBOR_SERIES_PAGE, $viewer, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, '.ev-crumbs'];

            // The events page's search index finds a series by its organization's name - never by a draft's
            yield "events page ({$who}) - search index: draft organization's name" => [...$harborClub, self::EVENTS, $viewer, mb_strtolower(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME), 'script[data-events-index]'];
        }

        // The puzzle of the draft event's round: "used at" (GetPuzzleSummary) - in the guests' part of the page
        yield 'puzzle page (guest) - draft event using the puzzle' => [...$draftNight, self::PUZZLE_PAGE, null, OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME];
        // ... and its round line in a signed-in player's Details (docs/features/events-page/high-frequency-series.md P24)
        yield 'puzzle page (player) - Details: draft event using the puzzle' => [...$draftNight, self::PUZZLE_PAGE, PlayerFixture::PLAYER_REGULAR, OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME, '#puzzleDetails'];

        yield 'sitemap - draft one-time event' => [...$draftNight, self::SITEMAP, null, '/en/events/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG . '<'];
        yield 'sitemap - draft edition' => [...$lanternDraft, self::SITEMAP, null, '/' . OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG . '<'];
        yield 'sitemap - draft series and its editions' => [...$quietPines, self::SITEMAP, null, OrganizationFixture::SERIES_QUIET_PINES_DRAFT_SLUG];
        yield 'sitemap - draft organization' => [...$harborClub, self::SITEMAP, null, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG];
        yield 'sitemap - past draft event' => [...$draftPast, self::SITEMAP, null, 'old-harbor-draft-classic'];

        // The add-time form's "Competition / event" picker (docs/features/events-page/high-frequency-series.md "The form"):
        // one-time events and series in the page, editions only by typing (S1) or in a series' preview list - and a
        // ?competition= / ?series= pre-selection does not sneak one in
        yield 'add-time picker - draft one-time event' => [...$draftNight, self::ADD_TIME, PlayerFixture::PLAYER_REGULAR, OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME];
        yield 'add-time picker - draft series' => [...$quietPines, self::ADD_TIME, PlayerFixture::PLAYER_REGULAR, OrganizationFixture::SERIES_QUIET_PINES_DRAFT_NAME];
        yield 'add-time typed search (S1) - draft edition' => [...$lanternDraft, self::PICKER_EDITIONS . rawurlencode(OrganizationFixture::EDITION_LANTERN_DRAFT_NAME), PlayerFixture::PLAYER_REGULAR, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME];
        yield 'add-time typed search (S1) - edition of a draft series' => [...$quietPines, self::PICKER_EDITIONS . rawurlencode(OrganizationFixture::EDITION_QUIET_PINES_1_NAME), PlayerFixture::PLAYER_REGULAR, OrganizationFixture::EDITION_QUIET_PINES_1_NAME];
        yield 'add-time series preview list - draft edition' => [...$lanternDraft, self::PICKER_PREVIEW . OrganizationFixture::SERIES_LANTERN_NIGHTS, PlayerFixture::PLAYER_REGULAR, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME];
        yield 'add-time pre-selection - draft one-time event' => [...$draftNight, self::ADD_TIME . '?competition=' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, PlayerFixture::PLAYER_REGULAR, OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME];
        yield 'add-time pre-selection - draft edition' => [...$lanternDraft, self::ADD_TIME . '?competition=' . OrganizationFixture::EDITION_LANTERN_DRAFT, PlayerFixture::PLAYER_REGULAR, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME];
        yield 'add-time pre-selection - draft series' => [...$quietPines, self::ADD_TIME . '?series=' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, PlayerFixture::PLAYER_REGULAR, 'value="series:' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT . '"'];
    }

    public function testApiV1ListsNoDraftAndAnswers404ForOne(): void
    {
        $browser = self::createClient();
        $token = OAuth2TestHelper::createAccessToken($browser, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID);
        OAuth2TestHelper::addBearerToken($browser, $token);
        $detail = '/api/v1/competitions/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT;

        $this->setDraft(self::COMPETITION, OrganizationFixture::COMPETITION_DRAFT_NIGHT, false);
        $browser->request('GET', '/api/v1/competitions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(OrganizationFixture::COMPETITION_DRAFT_NIGHT, (string) $browser->getResponse()->getContent(), 'No canary: the published event is not listed.');
        $browser->request('GET', $detail);
        self::assertResponseIsSuccessful();

        $this->setDraft(self::COMPETITION, OrganizationFixture::COMPETITION_DRAFT_NIGHT, true);
        $browser->request('GET', '/api/v1/competitions');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(OrganizationFixture::COMPETITION_DRAFT_NIGHT, (string) $browser->getResponse()->getContent());
        $browser->request('GET', $detail);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * API v1's series list (GET /api/v1/series, docs/features/events-page/high-frequency-series.md "API v1") - the ids a
     * client links solving times to: a draft series is not among them
     */
    public function testApiV1SeriesListLeavesADraftSeriesOut(): void
    {
        $browser = self::createClient();
        $token = OAuth2TestHelper::createAccessToken($browser, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID);
        OAuth2TestHelper::addBearerToken($browser, $token);

        $this->setDraft(self::SERIES, OrganizationFixture::SERIES_QUIET_PINES_DRAFT, false);
        $browser->request('GET', '/api/v1/series');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, (string) $browser->getResponse()->getContent(), 'No canary: the published series is not listed.');

        $this->setDraft(self::SERIES, OrganizationFixture::SERIES_QUIET_PINES_DRAFT, true);
        $browser->request('GET', '/api/v1/series');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, (string) $browser->getResponse()->getContent());
    }

    /**
     * The marketplace event select and its ?event= filter (GetMarketplaceEvents::SQL_QUALIFIES), the players page's
     * "upcoming events" figure and the admin menu's approval badge - counts and choices, checked at their read model.
     */
    public function testCountsAndChoicesLeaveDraftsOut(): void
    {
        self::createClient();
        $marketplace = self::getContainer()->get(GetMarketplaceEvents::class);
        $upcoming = self::getContainer()->get(GetUpcomingEventsCount::class);
        $adminCounts = self::getContainer()->get(GetAdminQueueCounts::class);

        $this->setDraft(self::COMPETITION, OrganizationFixture::COMPETITION_DRAFT_NIGHT, false);
        self::assertTrue($marketplace->qualifies(OrganizationFixture::COMPETITION_DRAFT_NIGHT), 'No canary: the published event is no marketplace event.');
        self::assertSame(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $marketplace->byId(OrganizationFixture::COMPETITION_DRAFT_NIGHT)->competitionId);
        $publishedCount = $upcoming->forScope(CommunityScope::world());

        $this->setDraft(self::COMPETITION, OrganizationFixture::COMPETITION_DRAFT_NIGHT, true);
        self::assertFalse($marketplace->qualifies(OrganizationFixture::COMPETITION_DRAFT_NIGHT));
        self::assertSame($publishedCount - 1, $upcoming->forScope(CommunityScope::world()), 'The players page counts a draft among the upcoming events.');

        try {
            $marketplace->byId(OrganizationFixture::COMPETITION_DRAFT_NIGHT);
            self::fail('A draft is offered as a marketplace event.');
        } catch (CompetitionNotEligibleForMarketplace) {
            // A draft is no marketplace event
        }

        // Waiting for approval and drafts: counted once published, never before
        $draftCount = $adminCounts->forViewer(true)->competitionApprovals;
        $this->setDraft(self::COMPETITION, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT, false);
        $this->setDraft(self::ORGANIZATION, OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, false);
        self::assertSame((int) $draftCount + 2, $adminCounts->forViewer(true)->competitionApprovals, 'No canary: published pending items are not counted.');
    }

    public function testTheApprovalQueueNeverListsADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->setDraft(self::COMPETITION, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT, false);
        $browser->request('GET', '/admin/competition-approvals');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT_NAME, (string) $browser->getResponse()->getContent(), 'No canary: the published pending event is not in the queue.');

        $this->setDraft(self::COMPETITION, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT, true);
        $browser->request('GET', '/admin/competition-approvals');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT_NAME, $content);
        self::assertStringNotContainsString(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT_NAME, $content);
    }

    public function testNobodyCanJoinADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['GET', 'POST'] as $method) {
            $browser->request($method, '/en/join-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, $method === 'POST' ? ['self_join' => '1'] : []);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        }

        // Its team neither: joining needs a publicly visible event (README P17)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/join-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id',
            ['id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        ));
    }

    public function testNobodyCanFollowADraftAndTheFlashNamesNone(): void
    {
        $browser = self::createClient();
        // Nobody - admins neither: a follow needs a publicly visible target
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->postFollow($browser, '/en/follow-event', 'competition:' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, json: true);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->postFollow($browser, '/en/follow-event', 'series:' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, json: true);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->postFollow($browser, '/en/follow-event', 'organization:' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, json: true);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Unfollowing a draft the player never followed: the flash does not tell its name
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->postFollow($browser, '/en/unfollow-event', 'competition:' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, return: '/en/events');
        self::assertResponseRedirects('/en/events');
        $browser->followRedirect();
        self::assertStringNotContainsString(OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME, (string) $browser->getResponse()->getContent());
        self::assertSelectorTextContains('main', 'You no longer follow it.');

        // ... while a series they did follow before it went back to draft is theirs to know
        $this->postFollow($browser, '/en/unfollow-event', 'series:' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, return: '/en/events');
        $browser->followRedirect();
        self::assertSelectorTextContains('main', 'You no longer follow ' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT_NAME);
    }

    public function testTheApiRefusesATimeInARoundOfADraft(): void
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_3000,
                'time' => '50:00',
                'round_id' => OrganizationFixture::ROUND_DRAFT_NIGHT,
            ]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_id = :id',
            ['id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        ));
    }

    private static function inThePast(string $competitionId): string
    {
        return "UPDATE competition SET date_from = CURRENT_DATE - 40, date_to = CURRENT_DATE - 40 WHERE id = '{$competitionId}'";
    }

    /**
     * @param self::COMPETITION|self::SERIES|self::ORGANIZATION $table
     */
    private function setDraft(string $table, string $id, bool $isDraft): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE {$table} SET is_draft = :isDraft WHERE id = :id",
            ['isDraft' => $isDraft ? 'true' : 'false', 'id' => $id],
        );
    }

    private function surface(KernelBrowser $browser, string $url, null|string $selector): string
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        if ($selector === null) {
            return (string) $browser->getResponse()->getContent();
        }

        $section = $crawler->filter($selector);

        return $section->count() > 0 ? $section->outerHtml() : '';
    }

    private function postFollow(KernelBrowser $browser, string $url, string $target, bool $json = false, string $return = ''): void
    {
        $server = ['HTTP_ORIGIN' => 'http://localhost'];

        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        $browser->request('POST', $url, ['_token' => 'csrf-token', 'target' => $target, 'return' => $return], server: $server);
    }
}
