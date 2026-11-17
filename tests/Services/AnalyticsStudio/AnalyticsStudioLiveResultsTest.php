<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\AnalyticsStudio;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioRound;
use SpeedPuzzling\Web\Query\GetCompetitorPage;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioLiveResults;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The studio's results are the competitor page's (analytics-studio.md §6, D8): on the "Results Cup" with competitor
 * pages and their live results on, every entry of the published Group A, the unpublished Group B and the unpublished
 * Pairs shows the same result, state, place, qualified mark and pieces on both - never a place for a live one. With the
 * live results off, the competitor page shows no result to the public, and the studio none at all.
 */
final class AnalyticsStudioLiveResultsTest extends KernelTestCase
{
    public function testTheStudioShowsWhatTheCompetitorPageShows(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $database = $container->get(Connection::class);
        $database->executeStatement('UPDATE competition SET competitor_pages = true, competitor_pages_live_results = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);

        $compared = 0;
        foreach ([F::ROUND_GROUP_A, F::ROUND_GROUP_B, F::ROUND_PAIRS] as $roundId) {
            $studio = $this->studio($roundId);

            foreach ($this->entryIds($roundId) as $entryId) {
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

                self::assertEquals($expected, $actual, "{$roundId} {$entryId}");
                if ($row !== null && $row['state'] === 'live') {
                    self::assertNull($row['rank']);
                }
                $compared++;
            }
        }

        self::assertGreaterThan(10, $compared);

        $database->executeStatement('UPDATE competition SET competitor_pages_live_results = false WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        self::assertSame([], $this->studio(F::ROUND_GROUP_B));
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
