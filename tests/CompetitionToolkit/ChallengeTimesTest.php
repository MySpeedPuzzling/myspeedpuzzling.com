<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\RoundChallengeTime;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Services\ChallengeRanking;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Challenges of a back-to-back block, the read side (real events W4, world-series-rules.md "Review amendments"): a
 * "Solve all 2 (order recorded)" round whose challenge time = its split minus the previous split - on the public round
 * page (chips + `?challenge=` rankings), and in the standing's round cell (organiser + public).
 */
final class ChallengeTimesTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string URL = '/en/events/results-cup/results/group-b';

    private KernelBrowser $browser;
    private string $challengeTwo;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->challengeTwo = SolveAllBlockSetup::seed($this->database());
    }

    public function testChipsShowEachChallengesOwnTimeNextToTheSplit(): void
    {
        $crawler = $this->browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $gina = $crawler->filter('[data-official-entry="participant_round:' . OfficialResultsFixture::ENTRY_B_GINA . '"]');
        // The first challenge's own time is its split - shown once; the second: 3900 - 1500
        self::assertCount(0, $gina->filter('[data-official-entry-puzzle="1"] [data-official-entry-puzzle-own]'));
        self::assertSame('2400', $gina->filter('[data-official-entry-puzzle="2"] [data-official-entry-puzzle-own]')->attr('data-official-entry-puzzle-own'));
        self::assertStringContainsString('(own 00:40:00)', $gina->filter('[data-official-entry-puzzle="2"]')->text());
    }

    public function testTheRoundPageLinksEachChallengesRanking(): void
    {
        $crawler = $this->browser->request('GET', self::URL);

        $links = $crawler->filter('[data-official-challenge-nav] [data-official-challenge-link]');
        self::assertSame(['', '1', '2'], $links->each(static fn (Crawler $link): string => (string) $link->attr('data-official-challenge-link')));
        self::assertSame(self::URL . '?challenge=2', $links->eq(2)->attr('href'));
        self::assertSame('page', $links->eq(0)->attr('aria-current'));
        // The round's own ranking is the table
        $this->assertSelectorNotExists('[data-official-challenge]');
        self::assertSame(['1', '2', '3'], $crawler->filter('[data-official-results] tbody tr')->each(static fn (Crawler $row): string => (string) $row->attr('data-official-rank')));
    }

    public function testAChallengeIsRankedByItsOwnTime(): void
    {
        $crawler = $this->browser->request('GET', self::URL . '?challenge=1');

        self::assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-official-challenge="1"]');
        $rows = $crawler->filter('[data-official-challenge] tbody tr');
        self::assertSame(
            [OfficialResultsFixture::ENTRY_B_HUGO, OfficialResultsFixture::ENTRY_B_GINA, OfficialResultsFixture::ENTRY_B_IVAN],
            $rows->each(static fn (Crawler $row): string => substr((string) $row->attr('data-official-entry'), strlen('participant_round:'))),
        );
        self::assertSame(['1', '2', '3'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-official-challenge-rank')));
        self::assertSame(['1200', '1500', '1800'], $rows->each(static fn (Crawler $row): string => (string) $row->filter('[data-official-challenge-time]')->attr('data-official-challenge-time')));
        // The round's place and result stay on the row
        self::assertSame('Round: 2. · 01:15:00', trim($rows->eq(0)->filter('[data-official-challenge-round]')->text()));

        // Challenge 2 by its label, any case: Gina 40:00, Ivan 53:20, Hugo 55:00
        $crawler = $this->browser->request('GET', self::URL . '?challenge=b');
        $rows = $crawler->filter('[data-official-challenge] tbody tr');
        self::assertSame(['2400', '3200', '3300'], $rows->each(static fn (Crawler $row): string => (string) $row->filter('[data-official-challenge-time]')->attr('data-official-challenge-time')));
        self::assertSame('page', $crawler->filter('[data-official-challenge-link="2"]')->attr('aria-current'));
    }

    public function testTiesShareAPlaceAndEntriesWithoutTheTimeComeAfterUnranked(): void
    {
        // Ivan ties Gina's challenge 1 (25:00); Hugo's splits were not recorded
        $this->record(OfficialResultsFixture::ENTRY_B_IVAN, [self::done(SolveAllBlockSetup::CHALLENGE_ONE, 1500), self::done($this->challengeTwo, 5000)]);
        $this->record(OfficialResultsFixture::ENTRY_B_HUGO, [['roundPuzzleId' => SolveAllBlockSetup::CHALLENGE_ONE, 'done' => true], self::done($this->challengeTwo, 4500)]);

        $crawler = $this->browser->request('GET', self::URL . '?challenge=1');

        $rows = $crawler->filter('[data-official-challenge] tbody tr');
        self::assertSame(['1', '1', ''], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-official-challenge-rank')));
        self::assertSame(
            'participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO,
            $rows->eq(2)->attr('data-official-entry'),
        );
        self::assertStringContainsString('Finished, time not recorded', $rows->eq(2)->text());

        // Challenge 2 for Hugo: its split is known, the previous one is not - no own time either
        $crawler = $this->browser->request('GET', self::URL . '?challenge=2');
        self::assertSame(['1', '2', ''], $crawler->filter('[data-official-challenge] tbody tr')->each(static fn (Crawler $row): string => (string) $row->attr('data-official-challenge-rank')));
    }

    public function testAHiddenPuzzleHasNoRankingAndAnUnknownChallengeShowsTheRound(): void
    {
        $this->database()->executeStatement(
            "UPDATE competition_round_puzzle SET hide_until_round_starts = true, hide_mode = 'entirely', reveal_mode = 'manual' WHERE id = :id",
            ['id' => SolveAllBlockSetup::CHALLENGE_ONE],
        );

        foreach (['?challenge=1', '?challenge=a', '?challenge=7', '?challenge='] as $query) {
            $crawler = $this->browser->request('GET', self::URL . $query);
            self::assertResponseIsSuccessful();
            $this->assertSelectorNotExists('[data-official-challenge]');
            self::assertSame(['1', '2', '3'], $crawler->filter('[data-official-results] tbody tr')->each(static fn (Crawler $row): string => (string) $row->attr('data-official-rank')), $query);
            self::assertSame(['', '2'], $crawler->filter('[data-official-challenge-link]')->each(static fn (Crawler $link): string => (string) $link->attr('data-official-challenge-link')), $query);
            self::assertStringNotContainsString('Puzzle 14', (string) $this->browser->getResponse()->getContent(), $query);
        }
    }

    public function testNoChallengeRankingsOnAPickOneRound(): void
    {
        $this->database()->executeStatement('UPDATE competition_round SET puzzles_to_solve = 1 WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]);

        $this->browser->request('GET', self::URL . '?challenge=1');

        self::assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-official-challenge-nav]');
        $this->assertSelectorNotExists('[data-official-challenge]');
    }

    /**
     * A challenge's ranking costs the page no statement more than the round's (the records ride on the entries
     * statement; it asks for no "Add to my profile" times).
     */
    public function testAChallengeRankingCostsNoStatementMore(): void
    {
        $round = $this->countedRequest(self::URL);
        $challenge = $this->countedRequest(self::URL . '?challenge=1');

        $this->assertSelectorExists('[data-official-challenge]');
        self::assertLessThanOrEqual($round, $challenge);
    }

    public function testTheStandingShowsTheBlocksChallengeTimes(): void
    {
        $standingId = PublishedStanding::insert($this->database(), OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'block', 'Block', 'solo', [OfficialResultsFixture::ROUND_GROUP_B]);
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);
        $results = self::getContainer()->get(GetStandingResults::class);

        foreach ([$results->organiserView($standing)->rows, $results->publicView($standing, null)->table->rows] as $rows) {
            $gina = array_values(array_filter($rows, static fn (StandingRow $row): bool => $row->name === 'Gina Quick'))[0] ?? null;
            self::assertNotNull($gina);
            $score = $gina->perRound[strtolower(OfficialResultsFixture::ROUND_GROUP_B)];
            self::assertSame([['label' => 'A', 'seconds' => 1500], ['label' => 'B', 'seconds' => 2400]], array_map(static fn (RoundChallengeTime $time): array => $time->toArray(), $score->challengeTimes));
            // The total stays the round's
            self::assertSame(3900, $score->scoreSeconds);
            self::assertSame([['label' => 'A', 'seconds' => 1500], ['label' => 'B', 'seconds' => 2400]], $score->toArray()['challengeTimes'] ?? null);
        }

        $crawler = $this->browser->request('GET', '/en/events/results-cup/standings/block');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('A 00:25:00 · B 00:40:00', $crawler->filter('[data-standing-challenges]')->first()->text());

        // A round without splits: nothing more in the cell, nor in its JSON
        $this->database()->executeStatement(
            'UPDATE competition_participant_round SET result_puzzles = NULL WHERE round_id = :round',
            ['round' => OfficialResultsFixture::ROUND_GROUP_B],
        );
        $score = $results->organiserView($standing)->rows[0]->perRound[strtolower(OfficialResultsFixture::ROUND_GROUP_B)];
        self::assertSame([], $score->challengeTimes);
        self::assertArrayNotHasKey('challengeTimes', $score->toArray());
    }

    public function testChallengeRankingSharesPlacesAndSkips(): void
    {
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 2, 'd' => 4, 'e' => null], ChallengeRanking::rank(['a' => 10, 'b' => 20, 'c' => 20, 'd' => 30, 'e' => null]));
        self::assertSame([2 => null, 0 => 1], ChallengeRanking::rank([2 => null, 0 => 5]));
        self::assertSame([], ChallengeRanking::rank([]));
    }

    public function testChallengeTimesNeedSplitsAndTwoPuzzles(): void
    {
        $record = RoundEntryPuzzles::fromWire([
            ['roundPuzzleId' => '018d0020-0000-0000-0000-00000000d001', 'done' => true, 'atSeconds' => 600],
            ['roundPuzzleId' => '018d0020-0000-0000-0000-00000000d002', 'done' => true],
            ['roundPuzzleId' => '018d0020-0000-0000-0000-00000000d003', 'done' => true, 'atSeconds' => 1500],
        ]);
        $labels = ['018d0020-0000-0000-0000-00000000d001' => 'Challenge 1', '018d0020-0000-0000-0000-00000000d002' => 'Challenge 2', '018d0020-0000-0000-0000-00000000d003' => 'Challenge 3'];

        self::assertSame([['label' => 'Challenge 1', 'seconds' => 600]], array_map(static fn (RoundChallengeTime $time): array => $time->toArray(), RoundChallengeTime::listOf($record, 3, $labels)));
        self::assertSame([], RoundChallengeTime::listOf($record, 1, $labels));
        self::assertSame([], RoundChallengeTime::listOf(null, 3, $labels));
    }

    private function countedRequest(string $url): int
    {
        $this->browser->request('GET', $url);
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($this->browser);
    }

    /**
     * @param list<array<string, mixed>> $puzzles
     */
    private function record(string $entryId, array $puzzles): void
    {
        $this->database()->executeStatement(
            'UPDATE competition_participant_round SET result_puzzles = :puzzles WHERE id = :id',
            ['puzzles' => json_encode($puzzles, JSON_THROW_ON_ERROR), 'id' => $entryId],
        );
    }

    /**
     * @return array{roundPuzzleId: string, done: true, atSeconds: int}
     */
    private static function done(string $roundPuzzleId, int $atSeconds): array
    {
        return ['roundPuzzleId' => $roundPuzzleId, 'done' => true, 'atSeconds' => $atSeconds];
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
