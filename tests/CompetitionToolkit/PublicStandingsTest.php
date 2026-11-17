<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Message\UnpublishCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\PublicStandingRow;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Public standings (competition toolkit A7, A-round-formats.md §3.4/§3.6): publish/unpublish, the public page counting
 * only rounds whose results are published ("Provisional" until all are), ranks equal to the organiser's preview once
 * everything is published, 404 for unpublished standings and drafts, the links (event page, round page, results
 * overview, organiser page) and the sitemap.
 */
final class PublicStandingsTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use WorldSeriesSetup;

    private const string SOLO_URL = '/en/events/world-series-test/standings/';

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv('COMPETITION_TOOLKIT_FOR_ORGANISERS', false);
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
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

        $crawler = $this->browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026');
        self::assertSame([$url], $crawler->filter('[data-event-standing-link]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        // The event URL of an edition redirects to the series URL
        $this->browser->request('GET', '/en/events/ejj-68-february-2026/standings/overall');
        self::assertResponseRedirects($url, 301);
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
