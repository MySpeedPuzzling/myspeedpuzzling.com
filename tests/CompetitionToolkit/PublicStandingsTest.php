<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\EditCompetitionStanding;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Message\UnpublishCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\PublicStandingRow;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\Standings\StandingPageBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

/**
 * Public standings (competition toolkit A7, A-round-formats.md §3.4/§3.6): publish/unpublish, the public page counting
 * only rounds whose results are published ("Provisional" until all are), ranks equal to the organiser's preview once
 * everything is published, 404 for unpublished standings and drafts, the links (event page, round page, results
 * overview, organiser page) and the sitemap. Competition toolkit v2 S4: the table part extracted into a partial renders
 * as before, the `public_standing_table` fragment (404 rules, guests' render once per generation, fresh after a signal).
 */
final class PublicStandingsTest extends WebTestCase
{
    use QueryCountAssertions;
    use WorldSeriesSetup;

    private const string SOLO_URL = '/en/events/world-series-test/standings/';

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testPublishAndUnpublish(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        self::assertNull($this->publishedAt($standingId));

        $this->messageBus->dispatch(new PublishCompetitionStanding($standingId, self::WS_COMPETITION));
        $first = $this->publishedAt($standingId);
        self::assertNotNull($first);

        // Publishing again keeps the first moment
        $this->messageBus->dispatch(new PublishCompetitionStanding($standingId, strtoupper(self::WS_COMPETITION)));
        self::assertSame($first, $this->publishedAt($standingId));

        $this->messageBus->dispatch(new UnpublishCompetitionStanding($standingId, self::WS_COMPETITION));
        self::assertNull($this->publishedAt($standingId));

        // A standing of another event is not found
        try {
            $this->messageBus->dispatch(new PublishCompetitionStanding($standingId, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
            self::fail('Expected CompetitionStandingNotFound');
        } catch (CompetitionStandingNotFound) {
            self::addToAssertionCount(1);
        }
        self::assertNull($this->publishedAt($standingId));
    }

    public function testTheOrganiserPublishesFromTheStandingsPage(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $slug = $this->slug($standingId);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-unpublished]');
        self::assertSelectorNotExists('[data-standing-public-link]');

        $this->browser->submit($crawler->filter('[data-standing-publish]')->form());
        self::assertResponseRedirects('/en/manage-event-standings/' . self::WS_COMPETITION, 303);
        self::assertNotNull($this->publishedAt($standingId));

        $crawler = $this->browser->followRedirect();
        self::assertSelectorExists('[data-standing-published]');
        self::assertSame(self::SOLO_URL . $slug, $crawler->filter('[data-standing-public-link]')->attr('href'));

        // A wrong token changes nothing
        $this->browser->request('POST', '/en/manage-event-standings/' . self::WS_COMPETITION . '/' . $standingId . '/publish', ['_publish_token' => 'nope', 'publish' => '0']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->publishedAt($standingId));

        $this->browser->submit($crawler->filter('[data-standing-unpublish]')->form());
        self::assertResponseRedirects();
        self::assertNull($this->publishedAt($standingId));

        // Somebody without the toolkit cannot publish
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('POST', '/en/manage-event-standings/' . self::WS_COMPETITION . '/' . $standingId . '/publish', ['_publish_token' => 'x', 'publish' => '1']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePublicPageCountsOnlyPublishedRoundsAndSaysProvisional(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $url = self::SOLO_URL . $this->slug($standingId);

        // Not published = not there, for the organiser too
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        TestingLogin::asPlayer($this->browser, self::WS_ORGANISER);
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->publish($standingId);
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-provisional]');
        self::assertSelectorTextContains('[data-standing-state]', 'after 3 of 6 rounds');
        // "World Series - Solo" says its category already: no [Solo] badge after it
        self::assertSelectorNotExists('[data-standing-category]');

        // Rounds 1-3 only: Dora is fastest there, Bob's unfinished round 6 and Dora's no-show in round 4 do not count yet
        self::assertSame([['1', 'Dora', '00:40:00'], ['2', 'Bob', '00:45:00'], ['3', 'Alice', '00:50:00'], ['4', 'Cyril', '00:55:00']], self::pageRows($crawler));
        self::assertSelectorNotExists('[data-standing-incomplete]');
        self::assertCount(4 * 3, $crawler->filter('[data-standing-score="not_counted"]'));
        self::assertCount(4 * 3, $crawler->filter('[data-standing-score="finished"]'));
        // Indexed once something counts; nothing about the organiser's tools or ids
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        $content = (string) $this->browser->getResponse()->getContent();
        foreach ($this->people as $participantId) {
            self::assertStringNotContainsString($participantId, $content);
        }
        self::assertStringNotContainsString('includes rounds whose results are not published', $content);

        // An unpublished round with results is still not counted, a newly published one is
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $this->soloRounds[3]]);
        $crawler = $this->browser->request('GET', $url);
        self::assertSelectorTextContains('[data-standing-state]', 'after 4 of 6 rounds');
        // Dora did not start round 4: she is listed last, without a rank
        self::assertSame([['1', 'Bob', '01:00:00'], ['2', 'Alice', '01:06:40'], ['3', 'Cyril', '01:13:20'], ['', 'Dora', '00:40:00']], self::pageRows($crawler));
        self::assertSelectorTextContains('[data-standing-incomplete]', '3 of 4 rounds');
    }

    /**
     * Competition toolkit T6 - Bob finished round 1 with 15 pieces missing (from the factory): with 30 s per missing
     * piece the count is shown and costs nothing while the standing's switch is off; on, it costs 15 × 30 s.
     */
    public function testPiecesMissingFromAFinishedPuzzleCostTimeOnlyWithTheSwitch(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant_round SET result_missing_pieces = 15 WHERE round_id = :roundId AND participant_id = :participantId',
            ['roundId' => $this->soloRounds[0], 'participantId' => $this->people['Bob']],
        );
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(30));
        $this->publish($standingId);
        $url = self::SOLO_URL . $this->slug($standingId);

        // Off (the default): Bob keeps his place, the count is next to his round 1 time and under his total
        $crawler = $this->browser->request('GET', $url);
        self::assertSame([['1', 'Dora', '00:40:00'], ['2', 'Bob', '00:45:00'], ['3', 'Alice', '00:50:00'], ['4', 'Cyril', '00:55:00']], self::pageRows($crawler));
        self::assertSelectorTextContains('[data-standing-rules]', '+30 s per missing piece');
        self::assertSelectorTextContains('[data-standing-rules]', 'pieces missing from a finished puzzle are not penalised');
        self::assertCount(2, $crawler->filter('[data-standing-missing="15"]'));
        self::assertSame(['00:45:00 · 15 pieces missing'], $crawler->filter('[data-standing-breakdown]')->each(static fn (Crawler $node): string => trim($node->text())));

        // On: + 7:30 - Bob drops behind Alice
        $this->messageBus->dispatch(new EditCompetitionStanding($standingId, self::WS_COMPETITION, 'World Series - Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(30, penaliseFinishedMissing: true)));
        $crawler = $this->browser->request('GET', $url);
        self::assertSame([['1', 'Dora', '00:40:00'], ['2', 'Alice', '00:50:00'], ['3', 'Bob', '00:52:30'], ['4', 'Cyril', '00:55:00']], self::pageRows($crawler));
        self::assertSelectorTextContains('[data-standing-rules]', 'pieces missing from a finished puzzle are penalised too');
        self::assertSame(['00:45:00 + 00:07:30 penalty · 15 pieces missing'], $crawler->filter('[data-standing-breakdown]')->each(static fn (Crawler $node): string => trim($node->text())));

        // The organiser's preview shows the same parts (every round, published or not: Bob's unfinished round 6 too)
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        self::assertResponseIsSuccessful();
        $breakdowns = $crawler->filter('[data-standing-preview] [data-standing-breakdown]')->each(static fn (Crawler $node): string => trim($node->text()));
        // Bob: 5 × 900 + 3600 = 2:15:00, 15 + 50 missing × 30 s = 32:30
        self::assertContains('02:15:00 + 00:32:30 penalty · 65 pieces missing', $breakdowns);
        $this->browser->request('GET', '/en/official-results/standings/' . $standingId);
        /** @var array{standing: array{rules: array<string, mixed>}, rows: list<array<string, mixed>>} $state */
        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($state['standing']['rules']['penaliseFinishedMissing']);
        $bob = array_values(array_filter($state['rows'], static fn (array $row): bool => $row['name'] === 'Bob'))[0];
        self::assertSame([8100, 65, 1950, 10050], [$bob['totalTimeSeconds'], $bob['totalMissingPieces'], $bob['totalPenaltySeconds'], $bob['totalSeconds']]);
    }

    public function testRanksEqualTheOrganisersPreviewOnceEveryRoundIsPublished(): void
    {
        $soloId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(2));
        $pairsId = $this->create('World Series - Pairs', RoundCategory::Duo, $this->pairRounds);
        $this->publish($soloId);
        $this->publish($pairsId);
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE competition_id = :id', ['id' => self::WS_COMPETITION]);

        $standings = self::getContainer()->get(GetCompetitionStandings::class);
        $results = self::getContainer()->get(GetStandingResults::class);

        foreach ([$soloId, $pairsId] as $standingId) {
            $standing = $standings->byId($standingId);
            $organiser = $results->organiserView($standing);
            $public = $results->publicView($standing, null);

            self::assertFalse($public->isProvisional());
            self::assertSame(
                array_map(static fn (StandingRow $row): array => [$row->key, $row->rank, $row->totalSeconds, $row->complete], $organiser->rows),
                array_map(static fn (PublicStandingRow $row): array => [$row->row->key, $row->row->rank, $row->row->totalSeconds, $row->row->complete], $public->rows),
            );
        }

        // Bob's unfinished round 6 = the limit + 50 missing pieces × 2 s on the page as well
        $crawler = $this->browser->request('GET', self::SOLO_URL . $this->slug($soloId));
        self::assertSelectorExists('[data-standing-final]');
        self::assertSelectorNotExists('[data-standing-provisional]');
        self::assertSame([['1', 'Alice', '01:40:00'], ['2', 'Cyril', '01:50:00'], ['3', 'Bob', '02:16:40'], ['', 'Dora', '01:06:40']], self::pageRows($crawler));
        self::assertCount(1, $crawler->filter('[data-standing-score="unfinished"]'));

        // The pairs page tabs to the solo one; a substitute's line-up is its own row under the same team name
        $crawler = $this->browser->request('GET', self::SOLO_URL . $this->slug($pairsId));
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-standing-tabs] a'));
        self::assertCount(3, $crawler->filter('[data-standing-row]'));
    }

    public function testADraftOrUnapprovedEventHasNoStandingPage(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $this->publish($standingId);
        $url = self::SOLO_URL . $this->slug($standingId);

        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::WS_COMPETITION]);
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        // Its team neither - like the round results pages, nothing of an event that is not public is shown
        TestingLogin::asPlayer($this->browser, self::WS_ORGANISER);
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->database->executeStatement('UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id', ['id' => self::WS_COMPETITION]);
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheEventRoundAndOverviewPagesLinkPublishedStandingsOnly(): void
    {
        $soloId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $soloUrl = self::SOLO_URL . $this->slug($soloId);

        $this->browser->request('GET', '/en/events/world-series-test');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-event-standings]');
        $this->browser->request('GET', '/en/events/world-series-test/results/solo-1');
        self::assertSelectorNotExists('[data-round-standings]');

        $this->publish($soloId);

        $crawler = $this->browser->request('GET', '/en/events/world-series-test');
        self::assertSame([$soloUrl], $crawler->filter('[data-event-standing-link]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        $crawler = $this->browser->request('GET', '/en/events/world-series-test/results/solo-1');
        self::assertSame([$soloUrl], $crawler->filter('[data-round-standing-link]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        // Not part of it: a pairs round
        $this->browser->request('GET', '/en/events/world-series-test/results/pairs-1');
        self::assertSelectorNotExists('[data-round-standings]');

        // The organiser's results overview, once a standing is published
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', '/en/manage-event-results/' . self::WS_COMPETITION);
        self::assertSelectorExists('[data-results-tool="standings"]');
    }

    public function testTheSitemapListsPublishedStandingsWithAPublishedRound(): void
    {
        $soloId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $lateId = $this->create('Late rounds', RoundCategory::Solo, array_slice($this->soloRounds, 3));
        $pairsId = $this->create('World Series - Pairs', RoundCategory::Duo, $this->pairRounds);
        $this->publish($soloId);
        $this->publish($lateId);

        $this->browser->request('GET', '/sitemap-events.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->browser->getResponse()->getContent();

        self::assertStringContainsString('/en/events/world-series-test/standings/' . $this->slug($soloId) . '<', $xml);
        // Nothing of it published yet (noindex) / not published at all
        self::assertStringNotContainsString('/standings/' . $this->slug($lateId) . '<', $xml);
        self::assertStringNotContainsString('/standings/' . $this->slug($pairsId) . '<', $xml);

        $crawler = $this->browser->request('GET', self::SOLO_URL . $this->slug($lateId));
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSelectorExists('[data-standing-empty]');
    }

    public function testAnEditionsStandingLivesUnderTheSeriesUrl(): void
    {
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => CompetitionSeriesFixture::ROUND_EJJ_68]);
        $participantId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, 'Olga Official', 'cz', :competitionId, 'imported')",
            ['id' => $participantId, 'competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68],
        );
        $this->database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, result_seconds, result_did_not_start) VALUES (:id, :participantId, :roundId, 3000, false)',
            ['id' => Uuid::uuid7()->toString(), 'participantId' => $participantId, 'roundId' => CompetitionSeriesFixture::ROUND_EJJ_68],
        );
        $category = $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :id', ['id' => CompetitionSeriesFixture::ROUND_EJJ_68]);
        assert(is_string($category));
        PublishedStanding::insert($this->database, CompetitionSeriesFixture::EDITION_EJJ_68, 'overall', 'Overall', $category, [CompetitionSeriesFixture::ROUND_EJJ_68]);

        $url = '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/standings/overall';
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame([['1', 'Olga Official', '00:50:00']], self::pageRows($crawler));
        // "Overall" does not say its category: the badge does
        self::assertSelectorExists('[data-standing-title] [data-standing-category]');

        $crawler = $this->browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026');
        self::assertSame([$url], $crawler->filter('[data-event-standing-link]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        // The event URL of an edition redirects to the series URL
        $this->browser->request('GET', '/en/events/ejj-68-february-2026/standings/overall');
        self::assertResponseRedirects($url, 301);
    }

    /**
     * Competition toolkit v2 S4: the table part moved into standing/_public_table.html.twig - the page's content renders
     * exactly as the template did before (tests/fixtures/standing-page-before-v2-s4.html.twig, a copy of it), apart from
     * the live wrapper around the table part. Rendered with every part of the table in use: a provisional standing, an
     * incomplete row, pieces missing, the round links.
     */
    public function testTheTablePartRendersAsBeforeTheExtraction(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant_round SET result_missing_pieces = 15 WHERE round_id = :roundId AND participant_id = :participantId',
            ['roundId' => $this->soloRounds[0], 'participantId' => $this->people['Bob']],
        );
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $this->soloRounds[3]]);
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(30));
        $this->publish($standingId);
        $slug = $this->slug($standingId);
        $url = self::SOLO_URL . $slug;

        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $live = $crawler->filter('[data-controller="standing-live"]');
        self::assertCount(1, $live);
        self::assertSame('/en/standing-table/' . $standingId, $live->attr('data-standing-live-url-value'));
        self::assertSame('/competition-standings/' . strtolower(self::WS_COMPETITION), $live->attr('data-standing-live-topic-value'));
        self::assertCount(1, $live->filter('turbo-frame#standing-table[target="_top"] [data-standing-table]'));

        $container = self::getContainer();
        $page = $container->get(StandingPageBuilder::class)->build(self::WS_COMPETITION, $slug);
        $request = Request::create($url);
        $request->setLocale('en');
        $request->attributes->set('_locale', 'en');
        $container->get(RequestStack::class)->push($request);
        $container->get('translator')->setLocale('en');

        // renderBlock() runs no top-level `set` of the template: its variables come with the context
        $context = [
            'page' => $page,
            'table' => $page->table,
            'standing' => $page->standing,
            'event' => $page->event,
            'event_url' => '/en/events/world-series-test',
            'standing_route' => 'event_standing',
            'round_route' => 'event_round_results',
            'route_params' => ['slug' => 'world-series-test'],
        ];
        $twig = $container->get(Environment::class);
        $before = $twig->createTemplate((string) file_get_contents(__DIR__ . '/../fixtures/standing-page-before-v2-s4.html.twig'))->renderBlock('content', $context);
        $after = $twig->load('standing.html.twig')->renderBlock('content', $context);

        self::assertStringContainsString('data-standing-incomplete', $before, 'No canary: the table has no incomplete row');
        self::assertStringContainsString('data-standing-missing="15"', $before, 'No canary: the table shows no missing pieces');
        self::assertStringContainsString('href="/en/events/world-series-test/results/solo-1"', $before, 'No canary: the table links no round');
        if (preg_match('~^(.*)<div data-controller="standing-live"[^>]*>\s*<turbo-frame id="standing-table"[^>]*>(.*)</turbo-frame>\s*<p [^>]*data-standing-live-target="status"[^>]*></p>\s*</div>(.*)$~s', $after, $parts) !== 1) {
            self::fail('The page has no live wrapper around its table part');
        }

        self::assertSame(self::collapsed($before), self::collapsed($parts[1] . $parts[2] . $parts[3]));
    }

    /**
     * The table fragment (competition toolkit v2 S4, `public_standing_table`): the page's table part in the same frame,
     * never stored by anybody, never indexed; the page's 404 rules.
     */
    public function testTheTableFragmentIsThePagesTablePart(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $unpublishedId = $this->create('World Series - Pairs', RoundCategory::Duo, $this->pairRounds);
        $this->publish($standingId);
        $fragment = '/en/standing-table/' . $standingId;

        $pageCrawler = $this->browser->request('GET', self::SOLO_URL . $this->slug($standingId));
        $crawler = $this->browser->request('GET', $fragment);
        self::assertResponseIsSuccessful();
        $response = $this->browser->getResponse();
        // Never shared: AnonymousCacheHeadersSubscriber must leave a guest's fragment alone (no public, no s-maxage)
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertCount(1, $crawler->filter('turbo-frame#standing-table[target="_top"]'));
        self::assertSame([['1', 'Dora', '00:40:00'], ['2', 'Bob', '00:45:00'], ['3', 'Alice', '00:50:00'], ['4', 'Cyril', '00:55:00']], self::pageRows($crawler));
        self::assertSame(
            self::collapsed($pageCrawler->filter('turbo-frame#standing-table')->html()),
            self::collapsed($crawler->filter('turbo-frame#standing-table')->html()),
        );

        // Another locale: its texts and links
        $this->browser->request('GET', '/cs/standing-table/' . $standingId);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('href="/en/', (string) $this->browser->getResponse()->getContent());

        // Unknown, not published, a draft (its team too), an unapproved event
        $this->browser->request('GET', '/en/standing-table/' . Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/standing-table/' . $unpublishedId);
        self::assertResponseStatusCodeSame(404);
        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::WS_COMPETITION]);
        $this->browser->request('GET', $fragment);
        self::assertResponseStatusCodeSame(404);
        TestingLogin::asPlayer($this->browser, self::WS_ORGANISER);
        $this->browser->request('GET', $fragment);
        self::assertResponseStatusCodeSame(404);
        $this->database->executeStatement('UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id', ['id' => self::WS_COMPETITION]);
        $this->browser->request('GET', $fragment);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Hundreds of open pages answer one signal within seconds: the guests' fragment is rendered once per generation
     * (warm: the event's visibility only), a signalled write is seen by the next fetch, a signed-in viewer gets a render
     * of his own.
     */
    public function testTheGuestsFragmentIsRenderedOncePerGeneration(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $this->publish($standingId);
        $fragment = '/en/standing-table/' . $standingId;
        // The test pool is an array adapter emptied by the reset after every request (production: Redis, kept)
        $this->browser->disableReboot();
        self::getContainer()->set('competition_standings_cache', new FilesystemAdapter('s4_' . bin2hex(random_bytes(6)), 0, sys_get_temp_dir() . '/public-standing-table-test'));

        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $fragment);
        self::assertResponseIsSuccessful();
        // The standing's event, visibility, the standing with its slugs, visibility (the builder's own), the rounds, the entries
        $this->assertQueryCountAtMost($this->browser, 7, 'cold');

        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $fragment);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->queryCount($this->browser), 'warm: ' . implode("\n", $this->executedSql($this->browser)));

        // An unsignalled change waits for the cache; the after-commit hook's new generation is seen at once
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $this->soloRounds[3]]);
        $this->browser->request('GET', $fragment);
        self::assertSelectorTextContains('[data-standing-state]', 'after 3 of 6 rounds', 'No canary: the fragment is not cached');
        $this->browser->getContainer()->get(CompetitionDisplayLiveUpdates::class)->roundChanged($this->soloRounds[3]);
        $this->browser->request('GET', $fragment);
        self::assertSelectorTextContains('[data-standing-state]', 'after 4 of 6 rounds');

        // Signed in: rendered for the viewer, never the guests' copy
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $fragment);
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $fragment);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(1, $this->queryCount($this->browser));
    }

    /**
     * An edition's fragment links its rounds under the series URL, like its page.
     */
    public function testAnEditionsFragmentLinksTheSeriesUrls(): void
    {
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => CompetitionSeriesFixture::ROUND_EJJ_68]);
        $participantId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, 'Olga Official', 'cz', :competitionId, 'imported')",
            ['id' => $participantId, 'competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68],
        );
        $this->database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, result_seconds, result_did_not_start) VALUES (:id, :participantId, :roundId, 3000, false)',
            ['id' => Uuid::uuid7()->toString(), 'participantId' => $participantId, 'roundId' => CompetitionSeriesFixture::ROUND_EJJ_68],
        );
        $category = $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :id', ['id' => CompetitionSeriesFixture::ROUND_EJJ_68]);
        assert(is_string($category));
        $standingId = PublishedStanding::insert($this->database, CompetitionSeriesFixture::EDITION_EJJ_68, 'overall', 'Overall', $category, [CompetitionSeriesFixture::ROUND_EJJ_68]);

        $pageCrawler = $this->browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/standings/overall');
        self::assertResponseIsSuccessful();
        $crawler = $this->browser->request('GET', '/en/standing-table/' . $standingId);
        self::assertResponseIsSuccessful();
        self::assertSame([['1', 'Olga Official', '00:50:00']], self::pageRows($crawler));
        self::assertSame(
            self::collapsed($pageCrawler->filter('turbo-frame#standing-table')->html()),
            self::collapsed($crawler->filter('turbo-frame#standing-table')->html()),
        );
        self::assertStringContainsString('href="/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/', (string) $this->browser->getResponse()->getContent());
    }

    private static function collapsed(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    /**
     * @return list<array{string, string, string}> rank, name, total
     */
    private static function pageRows(Crawler $crawler): array
    {
        return $crawler->filter('[data-standing-row]')->each(static fn (Crawler $row): array => [
            trim((string) $row->attr('data-standing-rank')),
            trim($row->filter('.lb-name')->first()->text()),
            trim($row->filter('[data-standing-total]')->text()),
        ]);
    }

    /**
     * @param list<string> $roundIds
     */
    private function create(string $name, RoundCategory $category, array $roundIds, StandingRules $rules = new StandingRules()): string
    {
        $id = Uuid::uuid7();
        $this->messageBus->dispatch(new CreateCompetitionStanding($id, self::WS_COMPETITION, $name, $category, $roundIds, $rules));

        return $id->toString();
    }

    private function publish(string $standingId): void
    {
        $this->messageBus->dispatch(new PublishCompetitionStanding($standingId, self::WS_COMPETITION));
    }

    /**
     * @phpstan-impure
     */
    private function publishedAt(string $standingId): null|string
    {
        $value = $this->database->fetchOne('SELECT published_at FROM competition_standing WHERE id = :id', ['id' => $standingId]);

        return is_string($value) ? $value : null;
    }

    private function slug(string $standingId): string
    {
        $slug = $this->database->fetchOne('SELECT slug FROM competition_standing WHERE id = :id', ['id' => $standingId]);
        assert(is_string($slug));

        return $slug;
    }
}
