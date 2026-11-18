<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\AnalyticsStudio;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioRound;
use SpeedPuzzling\Web\Query\GetCompetitorPage;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioLiveResults;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals\TimeProposalScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The studio's results (analytics-studio.md §6 as amended by FU-S): one ranking, no "official"/"unofficial" difference.
 * A published round shows exactly what the competitor page shows (the official rows); a round not published yet is
 * ranked live from the recorded results - what the judges and the desk entered - whatever the event's competitor page
 * settings, and a competitor's time proposal is never a result until a judge confirms it.
 */
final class AnalyticsStudioLiveResultsTest extends KernelTestCase
{
    use TimeProposalScenario;

    public function testAPublishedRoundShowsWhatTheCompetitorPageShows(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $database = $container->get(Connection::class);
        $database->executeStatement('UPDATE competition SET competitor_pages = true, competitor_pages_live_results = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);

        $studio = $this->studio(F::ROUND_GROUP_A);
        $compared = 0;

        foreach ($this->entryIds(F::ROUND_GROUP_A) as $entryId) {
            $page = $container->get(GetCompetitorPage::class)->of(F::COMPETITION_RESULTS_CUP, CompetitionTag::entry($entryId), null, TestClock::now());
            $entry = $page->rounds[0]->entry;
            self::assertNotNull($entry);

            $expected = $entry->result === null ? null : [
                'state' => $entry->resultState?->value,
                'seconds' => $entry->result->seconds,
                'piecesPlaced' => $entry->result->piecesPlaced,
                'piecesCount' => $entry->piecesCount,
                'rank' => $entry->rank,
                'qualified' => $entry->qualified,
            ];
            $row = $studio[$entryId] ?? null;
            $actual = $row === null ? null : [
                'state' => $row['state'],
                'seconds' => $row['seconds'],
                'piecesPlaced' => $row['piecesPlaced'],
                'piecesCount' => $row['piecesCount'],
                'rank' => $row['rank'],
                'qualified' => $row['qualified'],
            ];

            self::assertEquals($expected, $actual, $entryId);
            $compared++;
        }

        self::assertSame(6, $compared);
        self::assertSame([F::ENTRY_A_ANNA, F::ENTRY_A_BEN, F::ENTRY_A_CARA, F::ENTRY_A_DAN], array_keys($studio), 'rank order');
    }

    public function testARoundNotPublishedYetIsRankedLiveFromTheRecordedResultsWhateverTheCompetitorPageSettings(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        // Competitor pages and their live results off (the fixture) - and on: the same live ranking
        foreach ([false, true] as $pagesOn) {
            $database->executeStatement(
                'UPDATE competition SET competitor_pages = :on, competitor_pages_live_results = :on WHERE id = :id',
                ['on' => $pagesOn, 'id' => F::COMPETITION_RESULTS_CUP],
                ['on' => ParameterType::BOOLEAN],
            );

            $groupB = $this->studio(F::ROUND_GROUP_B);
            self::assertSame([
                F::ENTRY_B_GINA => ['state' => 'official', 'seconds' => 3900, 'rank' => 1, 'qualified' => false],
                F::ENTRY_B_HUGO => ['state' => 'official', 'seconds' => 4500, 'rank' => 2, 'qualified' => false],
                F::ENTRY_B_IVAN => ['state' => 'official', 'seconds' => 5000, 'rank' => 3, 'qualified' => false],
            ], array_map(static fn (array $row): array => array_intersect_key($row, ['state' => 1, 'seconds' => 1, 'rank' => 1, 'qualified' => 1]), $groupB));

            // Pairs: finished times first, then the unfinished one by pieces; a pair without a result is not listed
            $pairs = $this->studio(F::ROUND_PAIRS);
            self::assertSame([F::TEAM_SHARKS, F::TEAM_EDGES, F::TEAM_CORNERS], array_keys($pairs));
            self::assertSame([1, 2, 3], array_column($pairs, 'rank'));
            self::assertSame(['piecesPlaced' => 1700, 'piecesCount' => 2000], array_intersect_key($pairs[F::TEAM_CORNERS] ?? [], ['piecesPlaced' => 1, 'piecesCount' => 1]));
        }
    }

    public function testTiesShareAPlaceAndADidNotStartIsNotListed(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE competition_participant_round SET result_seconds = 3900 WHERE id = :id', ['id' => F::ENTRY_B_HUGO]);
        $database->executeStatement('UPDATE competition_participant_round SET result_seconds = NULL, result_did_not_start = true WHERE id = :id', ['id' => F::ENTRY_B_IVAN]);

        $groupB = $this->studio(F::ROUND_GROUP_B);

        self::assertSame([F::ENTRY_B_GINA => 1, F::ENTRY_B_HUGO => 1], array_map(static fn (array $row): null|int => $row['rank'], $groupB));
    }

    public function testAProposalIsNoResultUntilAJudgeConfirmsItThenEditsAndClearsAreRankedAtOnce(): void
    {
        self::bootKernel();
        self::runningRounds();

        // Anna tapped Stop on her phone: a proposal, not a result
        $answer = self::propose(self::ANNA_FINAL);
        self::assertSame('proposed', $answer->status, (string) $answer->reason);
        self::assertSame([], $this->studio(F::ROUND_FINAL));

        // The judge confirms it
        self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $answer->proposalId)]);
        self::assertSame(
            [F::ENTRY_FINAL_ANNA => ['seconds' => 598, 'rank' => 1]],
            array_map(static fn (array $row): array => ['seconds' => $row['seconds'], 'rank' => $row['rank']], $this->studio(F::ROUND_FINAL)),
        );

        // Edited, then cleared
        self::record([self::resultChange(self::ANNA_FINAL, ['seconds' => 598], ['seconds' => 610])]);
        self::assertSame(610, $this->studio(F::ROUND_FINAL)[F::ENTRY_FINAL_ANNA]['seconds'] ?? null);

        self::record([self::resultChange(self::ANNA_FINAL, ['seconds' => 610], null)]);
        self::assertSame([], $this->studio(F::ROUND_FINAL));
    }

    /**
     * @return array<string, array{key: string, state: string, seconds: null|int, piecesPlaced: null|int, piecesCount: null|int, rank: null|int, qualified: bool, puzzles?: list<array{label: string, done: bool, seconds: null|int, piecesPlaced: null|int}>}>
     */
    private function studio(string $roundId): array
    {
        $container = self::getContainer();
        $round = $container->get(GetAnalyticsStudioRound::class)->byId($roundId);
        $entrants = $container->get(GetAnalyticsStudioEntrants::class)->forRound($round);
        $puzzles = $container->get(RoundPuzzlesVisibleNow::class)->forRound($roundId, TestClock::now());
        $official = $round->published() ? $container->get(OfficialEntrantsView::class)->forRound($roundId, $round->category, null) : null;

        $rows = [];
        foreach (AnalyticsStudioLiveResults::of($round, $entrants, $puzzles, $official) as $row) {
            $rows[$row['key']] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function entryIds(string $roundId): array
    {
        /** @var list<string> $ids */
        $ids = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT id FROM competition_participant_round WHERE round_id = :round AND team_id IS NULL UNION ALL SELECT id FROM competition_team WHERE round_id = :round',
            ['round' => $roundId],
        );

        return $ids;
    }
}
