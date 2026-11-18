<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\Bracket\Bracket;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Results\StandingStage;
use SpeedPuzzling\Web\Services\CompetitionToolkit\StandingCalculator;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Value\BracketRoundStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\StandingEntryInput;
use SpeedPuzzling\Web\Value\StandingRoundInput;
use SpeedPuzzling\Web\Value\StandingScoreStatus;

/**
 * The `stages` classification across a bracket (competition toolkit v2 S5, displays-standings.md §4.3,
 * A-round-formats.md §3.5): the deepest stage reached first, the own round's places or one number across the stages,
 * parallel rounds, ties, a "did not start" in the final, a stage not classified yet.
 */
final class StandingCalculatorStagesTest extends TestCase
{
    /**
     * WJPC-like: two groups of 4 → a semifinal of 4 → a final of 2.
     */
    public function testTheDeepestStageFirstEachStageWithItsOwnRoundsPlaces(): void
    {
        $rows = StandingCalculator::stages(self::wjpc(), self::wjpcRounds(), false);

        self::assertSame(
            ['a' => [3, 'f', 1], 'e' => [3, 'f', 2], 'b' => [2, 's', 3], 'f' => [2, 's', 4], 'c' => [1, 'g1', 3], 'd' => [1, 'g1', 4], 'g' => [1, 'g2', 3], 'h' => [1, 'g2', 4]],
            self::stages($rows),
        );
        // No number across the stages, no invented stage label
        self::assertSame([null], array_values(array_unique(array_map(static fn (StandingRow $row): null|int => $row->rank, $rows))));
        self::assertSame('Final', $rows[0]->stageName);
        self::assertSame('Group 1 / Group 2', $rows[7]->stageName);
        // The row carries its stage round only
        self::assertSame(['f'], array_keys($rows[0]->perRound));
        self::assertSame(StandingScoreStatus::Finished, $rows[0]->perRound['f']->status);
        self::assertSame(3000, $rows[0]->totalSeconds);
        self::assertTrue($rows[0]->complete);
        self::assertFalse($rows[0]->qualifiedForStage);
    }

    public function testNumberedAcrossTheStagesDeeperStageFirstThenTheSeedAcrossParallelRounds(): void
    {
        $rows = StandingCalculator::stages(self::wjpc(), self::wjpcRounds(), true);

        // Groups: c (3rd of a group won in 1000 s, 1300 s = 1.3) before g (3rd of a group won in 2000 s, 2800 s = 1.4);
        // the two 4th: h finished, d did not
        self::assertSame(['a' => 1, 'e' => 2, 'b' => 3, 'f' => 4, 'c' => 5, 'g' => 6, 'h' => 7, 'd' => 8], self::numbers($rows));
        self::assertSame(3, $rows[4]->stageRank);
    }

    public function testParallelFinalsAreGroupedPerRoundOrNumberedByTheSeedWithSharedNumbers(): void
    {
        $bracket = self::bracket([[['g', 'Groups']], [['f1', 'Final A'], ['f2', 'Final B']]]);
        $rounds = [
            'g' => self::round('g', ['a' => RoundEntryResult::finished(100), 'b' => RoundEntryResult::finished(110), 'c' => RoundEntryResult::finished(120), 'd' => RoundEntryResult::finished(130), 'x' => RoundEntryResult::finished(500)]),
            'f1' => self::round('f1', ['a' => RoundEntryResult::finished(100), 'b' => RoundEntryResult::finished(110)]),
            'f2' => self::round('f2', ['c' => RoundEntryResult::finished(200), 'd' => RoundEntryResult::finished(240)]),
        ];

        $grouped = StandingCalculator::stages($bracket, $rounds, false);
        self::assertSame(['a' => [2, 'f1', 1], 'b' => [2, 'f1', 2], 'c' => [2, 'f2', 1], 'd' => [2, 'f2', 2], 'x' => [1, 'g', 5]], self::stages($grouped));
        self::assertSame('Final A / Final B', $grouped[0]->stageName);

        // Both winners won their round: the same place; b 1.1 × its winner, d 1.2 × its winner
        self::assertSame(['a' => 1, 'c' => 1, 'b' => 3, 'd' => 4, 'x' => 5], self::numbers(StandingCalculator::stages($bracket, $rounds, true)));
    }

    public function testEqualResultsInARoundShareTheNumberAndTheNextSkips(): void
    {
        $bracket = self::bracket([[['g', 'Groups']], [['f', 'Final']]]);
        $rounds = [
            'g' => self::round('g', ['a' => RoundEntryResult::finished(100), 'b' => RoundEntryResult::finished(100), 'c' => RoundEntryResult::finished(100), 'd' => RoundEntryResult::finished(100), 'e' => RoundEntryResult::finished(900)]),
            'f' => self::round('f', ['a' => RoundEntryResult::finished(100), 'b' => RoundEntryResult::finished(200), 'c' => RoundEntryResult::finished(200), 'd' => RoundEntryResult::finished(300)]),
        ];

        self::assertSame(['a' => 1, 'b' => 2, 'c' => 2, 'd' => 4, 'e' => 5], self::numbers(StandingCalculator::stages($bracket, $rounds, true)));
    }

    public function testADidNotStartInTheFinalStaysInTheFinalWithoutANumberAndCountsForThePlacesBelow(): void
    {
        $bracket = self::bracket([[['g', 'Groups']], [['f', 'Final']]]);
        $rounds = [
            'g' => self::round('g', ['a' => RoundEntryResult::finished(100), 'b' => RoundEntryResult::finished(110), 'c' => RoundEntryResult::finished(120), 'z' => RoundEntryResult::didNotStart()]),
            'f' => self::round('f', ['a' => RoundEntryResult::didNotStart(), 'b' => RoundEntryResult::finished(300)]),
        ];

        $rows = StandingCalculator::stages($bracket, $rounds, true);

        // z never had a ranked result anywhere - left out, like a sum standing's entrant without a score
        self::assertSame(['b' => [2, 'f', 1], 'a' => [2, 'f', null], 'c' => [1, 'g', 3]], self::stages($rows));
        self::assertSame(['b' => 1, 'a' => null, 'c' => 3], self::numbers($rows));
        self::assertSame(StandingScoreStatus::DidNotStart, $rows[1]->perRound['f']->status);
        self::assertFalse($rows[1]->complete);
    }

    public function testQualifiedForAStageNotClassifiedYetIsOneUnrankedGroupInItsPlace(): void
    {
        $rounds = self::wjpcRounds();
        // The semifinal and the final are not classified yet (public: not published) - their entries are ignored
        $rounds['s'] = new StandingRoundInput('s', 90, false, $rounds['s']->entries);
        $rounds['f'] = new StandingRoundInput('f', 90, false, $rounds['f']->entries);

        $rows = StandingCalculator::stages(self::wjpc(), $rounds, true);

        self::assertSame(['a', 'b', 'e', 'f'], array_map(static fn (StandingRow $row): string => $row->key, array_slice($rows, 0, 4)));
        foreach (array_slice($rows, 0, 4) as $row) {
            self::assertTrue($row->qualifiedForStage);
            self::assertSame(2, $row->stage);
            self::assertSame('Semifinal', $row->stageName);
            self::assertNull($row->stageRoundId);
            self::assertNull($row->stageRank);
            self::assertNull($row->rank);
            self::assertFalse($row->complete);
        }

        // The eliminated keep their places below everybody still in
        self::assertSame(['c' => 5, 'g' => 6, 'h' => 7, 'd' => 8], array_slice(self::numbers($rows), 4));
        self::assertSame(['c' => [1, 'g1', 3], 'd' => [1, 'g1', 4], 'g' => [1, 'g2', 3], 'h' => [1, 'g2', 4]], array_slice(self::stages(StandingCalculator::stages(self::wjpc(), $rounds, false)), 4));
    }

    public function testAFinalClassifiedBeforeItsSemifinalPlacesItsPeopleAboveTheQualified(): void
    {
        $rounds = self::wjpcRounds();
        $rounds['s'] = new StandingRoundInput('s', 90, false, $rounds['s']->entries);

        $rows = StandingCalculator::stages(self::wjpc(), $rounds, true);

        // The finalists are in the final; the semifinal's people are only known as qualified from the groups
        self::assertSame(['a' => [3, 'f', 1], 'e' => [3, 'f', 2], 'b' => [2, null, null], 'f' => [2, null, null]], array_slice(self::stages($rows), 0, 4));
        self::assertSame(['a' => 1, 'e' => 2, 'b' => null, 'f' => null, 'c' => 5], array_slice(self::numbers($rows), 0, 5));
    }

    public function testNothingClassifiedGivesNoRows(): void
    {
        $rounds = array_map(static fn (StandingRoundInput $round): StandingRoundInput => new StandingRoundInput($round->roundId, 90, false, $round->entries), self::wjpcRounds());

        self::assertSame([], StandingCalculator::stages(self::wjpc(), $rounds, true));
        self::assertSame([], StandingCalculator::stages(self::wjpc(), [], false));
    }

    public function testTheStageList(): void
    {
        $rounds = self::wjpcRounds();
        unset($rounds['f']);

        $stages = StandingCalculator::stageList(self::wjpc(), $rounds);

        self::assertSame([1, 2, 3], array_map(static fn (StandingStage $stage): int => $stage->number, $stages));
        self::assertSame([true, true, false], array_map(static fn (StandingStage $stage): bool => $stage->classified, $stages));
        self::assertSame(['g1' => 'Group 1', 'g2' => 'Group 2'], $stages[0]->rounds);
        self::assertSame('A / B / C +2', StandingStage::nameOf(['A', 'B', 'C', 'D', 'E']));
    }

    private static function wjpc(): Bracket
    {
        return self::bracket([[['g1', 'Group 1'], ['g2', 'Group 2']], [['s', 'Semifinal']], [['f', 'Final']]]);
    }

    /**
     * @return array<string, StandingRoundInput>
     */
    private static function wjpcRounds(): array
    {
        return [
            'g1' => self::round('g1', ['a' => RoundEntryResult::finished(1000), 'b' => RoundEntryResult::finished(1100), 'c' => RoundEntryResult::finished(1300), 'd' => RoundEntryResult::unfinished(400)], qualified: ['a', 'b']),
            'g2' => self::round('g2', ['e' => RoundEntryResult::finished(2000), 'f' => RoundEntryResult::finished(2100), 'g' => RoundEntryResult::finished(2800), 'h' => RoundEntryResult::finished(3000)], qualified: ['e', 'f']),
            's' => self::round('s', ['a' => RoundEntryResult::finished(2000), 'e' => RoundEntryResult::finished(2100), 'b' => RoundEntryResult::finished(2200), 'f' => RoundEntryResult::finished(2300)], qualified: ['a', 'e']),
            'f' => self::round('f', ['a' => RoundEntryResult::finished(3000), 'e' => RoundEntryResult::finished(3100)]),
        ];
    }

    /**
     * @param list<list<array{string, string}>> $stages [id, name] per round, stage 1 first
     */
    private static function bracket(array $stages): Bracket
    {
        $startsAt = new DateTimeImmutable('2026-11-18 09:00:00');

        return new Bracket(
            category: RoundCategory::Solo,
            stages: array_map(static fn (array $rounds): array => array_map(static fn (array $round): BracketRound => new BracketRound(
                id: $round[0],
                name: $round[1],
                category: RoundCategory::Solo,
                startsAt: $startsAt,
                startTimeKnown: true,
                timezone: 'Europe/Prague',
                status: BracketRoundStatus::Published,
                slug: $round[0],
            ), $rounds), $stages),
            gaps: [],
            feeds: [],
        );
    }

    /**
     * @param array<string, RoundEntryResult> $results key => result (ranked like the round page)
     * @param list<string> $qualified
     */
    private static function round(string $id, array $results, array $qualified = []): StandingRoundInput
    {
        $ranks = OfficialResultsRanking::rank($results);
        $entries = [];

        foreach ($results as $key => $result) {
            $entries[] = new StandingEntryInput((string) $key, $result, $ranks[$key], 1000, (string) $key, qualified: in_array($key, $qualified, true));
        }

        return new StandingRoundInput($id, 90, true, $entries);
    }

    /**
     * @param list<StandingRow> $rows
     * @return array<string, array{null|int, null|string, null|int}> key => [stage, stage round, place there], in order
     */
    private static function stages(array $rows): array
    {
        $stages = [];
        foreach ($rows as $row) {
            $stages[$row->key] = [$row->stage, $row->stageRoundId, $row->stageRank];
        }

        return $stages;
    }

    /**
     * @param list<StandingRow> $rows
     * @return array<string, null|int> key => the number across the stages, in order
     */
    private static function numbers(array $rows): array
    {
        $numbers = [];
        foreach ($rows as $row) {
            $numbers[$row->key] = $row->rank;
        }

        return $numbers;
    }
}
