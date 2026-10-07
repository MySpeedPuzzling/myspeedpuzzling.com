<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The public round page with the organiser's published official results (docs/features/competitions-management/official-results.md),
 * on OfficialResultsFixture's "Results Cup" - Group A is published, Group B and Pairs are not.
 */
final class OfficialRoundResultsPageTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string GROUP_A_URL = '/en/events/results-cup/results/group-a';
    private const string GROUP_B_URL = '/en/events/results-cup/results/group-b';
    private const string PAIRS_URL = '/en/events/results-cup/results/pairs';
    private const string EVENT_URL = '/en/events/results-cup';
    private const string ADMIN_USER_ID = 'auth0|admin003';

    public function testAnUnpublishedRoundShowsNoOfficialResults(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::GROUP_B_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-official-results]');
        $this->assertSelectorNotExists('[data-round-player-times]');
        // Nobody added a time - the page says so, as without official results
        $this->assertSelectorTextContains('main', 'No results yet');
        $this->assertSelectorTextNotContains('main', 'Gina Quick');
        self::assertStringContainsString('ranked by time', (string) $browser->getCrawler()->filter('meta[name="description"]')->attr('content'));
    }

    public function testThePublishedResultsLeadThePageWithTheOfficialPlaces(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::GROUP_A_URL);

        $this->assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-official-results] tbody tr');
        self::assertSame(['1', '2', '2', '4'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-official-rank')));
        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished'],
            $rows->each(static fn (Crawler $row): string => $row->filter('.lb-name')->text()),
        );
        self::assertSame(
            ['01:00:00', '01:10:00', '01:10:00', '850 / 1000 pcs'],
            $rows->each(static fn (Crawler $row): string => $row->filter('.lb-time-value')->text()),
        );

        // The podium, the two qualified with the legend
        self::assertCount(3, $crawler->filter('[data-official-results] .lb-rank-podium'));
        self::assertCount(2, $crawler->filter('[data-official-results] tbody [data-official-qualified]'));
        $this->assertSelectorExists('[data-official-legend]');

        // Did not start and no result yet are the organiser's - and the table numbers are never public
        $this->assertSelectorTextNotContains('main', 'Eva Noshow');
        $this->assertSelectorTextNotContains('main', 'Filip Pending');
        $this->assertSelectorTextNotContains('[data-official-results]', 'Table');

        // Linked player links the profile, the others keep the organiser's name
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_ADMIN, $rows->eq(0)->filter('a.lb-name')->attr('href'));
        self::assertCount(0, $rows->eq(1)->filter('a.lb-name'));

        // Accessible table, and the page says what it is
        $this->assertSelectorTextContains('[data-official-results] caption', 'Official results of Group A');
        $this->assertSelectorExists('[data-official-results] th[scope="col"]');
        self::assertStringContainsString('Official results of Group A', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertStringContainsString('4 entrants', (string) $crawler->filter('meta[name="description"]')->attr('content'));

        // Nobody added a time: no folded list, no "not the official placings" note
        $this->assertSelectorNotExists('[data-round-player-times]');
        $this->assertSelectorTextNotContains('main', 'not the official placings');
    }

    public function testTimesPuzzlersAddedFoldBelowTheOfficialResultsWithTheirNote(): void
    {
        $browser = self::createClient();
        $this->addTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, '01:20:00');

        $crawler = $browser->request('GET', self::GROUP_A_URL);

        $this->assertResponseIsSuccessful();
        $details = $crawler->filter('details[data-round-player-times]');
        self::assertCount(1, $details);
        self::assertNull($details->attr('open'), 'The times puzzlers added are folded');
        self::assertSame('Times added by puzzlers (1)', $details->filter('summary')->text());
        self::assertCount(1, $details->filter('[data-round-result-status="finished"]'));
        self::assertStringContainsString('not the official placings', $details->text());
        // The note is only inside - the official results above are the official placings
        self::assertCount(1, $crawler->filter('main')->reduce(static fn (Crawler $main): bool => substr_count($main->text(), 'not the official placings') === 1));
        // The official results come first
        $html = (string) $browser->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'data-round-player-times'), strpos($html, 'data-official-results'));
    }

    public function testTheOrganisersOwnLinkIsTheirsNotTheOfficialResults(): void
    {
        $browser = self::createClient();
        $this->database()->executeStatement(
            "UPDATE competition_round SET results_link = 'https://example.com/cup/group-a' WHERE id IN (:groupA, :groupB)",
            ['groupA' => OfficialResultsFixture::ROUND_GROUP_A, 'groupB' => OfficialResultsFixture::ROUND_GROUP_B],
        );

        $crawler = $browser->request('GET', self::GROUP_A_URL);
        $link = $crawler->filter('a[href="https://example.com/cup/group-a?utm_source=myspeedpuzzling"]');
        self::assertCount(1, $link);
        self::assertSame("Organiser's results", trim($link->text()));

        // Without official results the same link is still "the official results"
        $crawler = $browser->request('GET', self::GROUP_B_URL);
        self::assertSame('Official results', trim($crawler->filter('a[href="https://example.com/cup/group-a?utm_source=myspeedpuzzling"]')->text()));
    }

    public function testPairsShowTheirNameAndMembers(): void
    {
        $browser = self::createClient();
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);

        $crawler = $browser->request('GET', self::PAIRS_URL);

        $this->assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-official-results] tbody tr');
        self::assertSame(
            ['Puzzle Sharks', 'Edge Hunters', 'Corner Pieces'],
            $rows->each(static fn (Crawler $row): string => trim($row->filter('[data-official-team-name]')->text())),
        );
        self::assertSame(['Anna Fast', 'Ben Steady'], $rows->eq(0)->filter('.lb-name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame('1700 / 2000 pcs', $rows->eq(2)->filter('.lb-time-value')->text());
        $this->assertSelectorTextContains('[data-official-results] thead', 'Pair');
    }

    public function testAddToMyProfileIsOfferedOnTheViewersOwnFinishedEntryOnly(): void
    {
        $browser = self::createClient();
        $offers = '[data-official-add-to-profile]';

        $browser->request('GET', self::GROUP_A_URL);
        $this->assertSelectorNotExists($offers);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', self::GROUP_A_URL);

        $links = $crawler->filter($offers);
        self::assertCount(1, $links);
        self::assertSame(
            sprintf(
                '/en/puzzle-add/%s?competition=%s&official_entry=participant_round:%s',
                PuzzleFixture::PUZZLE_1000_05,
                OfficialResultsFixture::COMPETITION_RESULTS_CUP,
                OfficialResultsFixture::ENTRY_A_ANNA,
            ),
            $links->attr('href'),
        );
        self::assertSame(OfficialResultsFixture::ENTRY_A_ANNA, explode(':', (string) $links->closest('tr')?->attr('data-official-entry'))[1]);
        self::assertStringContainsString('table-active-player', (string) $links->closest('tr')?->attr('class'));

        // Once the time is on the profile
        $this->addTime(self::ADMIN_USER_ID, '01:00:00');
        $crawler = $browser->request('GET', self::GROUP_A_URL);
        $this->assertSelectorNotExists($offers);
        self::assertSame('On your profile', trim($crawler->filter('[data-official-on-profile]')->text()));
    }

    public function testAPlayerTheOrganiserLinkedToNothingIsOfferedTheEntriesNobodyIsLinkedTo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', self::GROUP_A_URL);

        // Ben and Cara - not Anna (somebody else's), not Dan (did not finish)
        self::assertSame(
            ['participant_round:' . OfficialResultsFixture::ENTRY_A_BEN, 'participant_round:' . OfficialResultsFixture::ENTRY_A_CARA],
            $crawler->filter('[data-official-add-to-profile]')->each(static fn (Crawler $link): string => (string) $link->closest('tr')?->attr('data-official-entry')),
        );
    }

    /**
     * Query budget: an unpublished round page runs exactly what it ran before, a published one a bounded constant number
     * more - one statement for the entries, one for the viewer's own times when a row could offer "Add to my profile".
     */
    public function testPublishedResultsCostABoundedNumberOfStatements(): void
    {
        // A guest: the entries. Anna's player: her offer needs her times. Hugo's: the entries nobody is linked to too
        foreach ([[null, 1], [PlayerFixture::PLAYER_ADMIN, 2], [PlayerFixture::PLAYER_REGULAR, 2]] as [$viewer, $extra]) {
            $browser = self::createClient();
            $this->publish(OfficialResultsFixture::ROUND_GROUP_A);

            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            // Warm-up: whatever the page caches, both counted requests find it cached
            $browser->request('GET', self::GROUP_A_URL);

            $this->startCountingQueries($browser);
            $browser->request('GET', self::GROUP_A_URL);
            $this->assertSelectorExists('[data-official-results]');
            $published = $this->queryCount($browser);

            $this->database()->executeStatement('UPDATE competition_round SET results_published_at = NULL WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]);

            $this->startCountingQueries($browser);
            $browser->request('GET', self::GROUP_A_URL);
            $this->assertSelectorNotExists('[data-official-results]');
            $unpublished = $this->queryCount($browser);

            self::assertSame($unpublished + $extra, $published, sprintf('Viewer %s: the official results cost %d statements.', $viewer ?? 'guest', $published - $unpublished));
            // The event's own row asks whether any round shows official results (GetCompetitionEvents::byId()) - nothing else
            self::assertSame([], array_values(array_filter(
                $this->executedSql($browser),
                static fn (string $sql): bool => (str_contains($sql, 'competition_participant_round') || str_contains($sql, 'competition_team'))
                    && str_contains($sql, 'has_published_official_results') === false,
            )), 'An unpublished round page never reads the official results.');

            self::ensureKernelShutdown();
        }
    }

    public function testTheEventPageListsTheRoundAndSaysResults(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::EVENT_URL);

        $this->assertResponseIsSuccessful();
        // A past event with official results: "Results" in the title, the official description, the round's button
        self::assertSame('Results Cup 2026 Results – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertStringContainsString('the official results round by round', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame(['Group A'], $crawler->filter('[data-event-round-results] a')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    public function testOfficialResultsCostTheEventPageNoStatement(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::EVENT_URL);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::EVENT_URL);
        $this->assertSelectorExists('[data-event-round-results]');
        $withOfficialResults = $this->queryCount($browser);

        $this->database()->executeStatement('UPDATE competition_round SET results_published_at = NULL WHERE competition_id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::EVENT_URL);
        $this->assertSelectorNotExists('[data-event-round-results]');
        self::assertSame($withOfficialResults, $this->queryCount($browser));
        // Not a word about official results in any statement but the event's own row
        self::assertCount(1, array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'official_round'),
        ));
        self::assertSame('Results Cup 2026 – MySpeedPuzzling', $browser->getCrawler()->filter('title')->text());
    }

    public function testAnEditionWithPublishedResultsSaysSo(): void
    {
        $browser = self::createClient();
        $database = $this->database();
        // EJJ #68's only round, an official result in it, published
        $participantId = Uuid::uuid7()->toString();
        $database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, 'Olga Official', 'cz', :competitionId, 'imported')",
            ['id' => $participantId, 'competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68],
        );
        $database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, result_seconds, result_did_not_start) VALUES (:id, :participantId, :roundId, 3000, false)',
            ['id' => Uuid::uuid7()->toString(), 'participantId' => $participantId, 'roundId' => CompetitionSeriesFixture::ROUND_EJJ_68],
        );
        $database->executeStatement('UPDATE competition_round SET results_published_at = NOW() WHERE id = :id', ['id' => CompetitionSeriesFixture::ROUND_EJJ_68]);

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('the official results round by round', (string) $crawler->filter('meta[name="description"]')->attr('content'));

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round');
        self::assertSame('Olga Official', $crawler->filter('[data-official-results] tbody .lb-name')->text());
    }

    public function testTheNotificationOfTheFirstPublishLeadsToTheRoundPage(): void
    {
        $browser = self::createClient();
        $this->database()->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, target_competition_round_id) VALUES (:id, :playerId, 'OfficialResultPublished', NOW(), :roundId)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => PlayerFixture::PLAYER_ADMIN, 'roundId' => OfficialResultsFixture::ROUND_GROUP_A],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/notifications');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'The official results of Group A at Results Cup are out - see your result.');
        self::assertCount(1, $crawler->filter('a[href="' . self::GROUP_A_URL . '"]'));
    }

    private function publish(string $roundId): void
    {
        $this->database()->executeStatement(
            'UPDATE competition_round SET results_published_at = NOW(), results_first_published_at = NOW() WHERE id = :id',
            ['id' => $roundId],
        );
    }

    private function addTime(string $userId, string $time): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: PuzzleFixture::PUZZLE_1000_05,
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
