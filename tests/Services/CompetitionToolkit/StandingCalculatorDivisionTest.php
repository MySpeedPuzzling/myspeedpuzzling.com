<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Services\CompetitionToolkit\StandingCalculator;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\StandingEntryInput;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingRoundInput;
use SpeedPuzzling\Web\Value\StandingRules;
use SpeedPuzzling\Web\Value\StandingScoreStatus;

/**
 * A standing of one division (real events L2a, shared-puzzles-and-divisions.md D7): StandingRoundInput::forDivision()
 * keeps the division's entries only, an entrant of another division (or none) in a round is "not in the round" there -
 * never a no-show - and a round counts once something of the division is recorded in it.
 */
final class StandingCalculatorDivisionTest extends TestCase
{
    private const string SENIOR = '018d00a6-0000-0000-0000-00000000d001';
    private const string REGULAR = '018d00a6-0000-0000-0000-00000000d002';

    public function testOnlyTheDivisionsEntriesCount(): void
    {
        $round = self::round('r1', [
            self::entry('anna', RoundEntryResult::finished(1000), self::SENIOR),
            self::entry('ben', RoundEntryResult::finished(900), self::REGULAR),
            self::entry('cara', RoundEntryResult::finished(1100), null),
        ])->forDivision(strtoupper(self::SENIOR));

        self::assertSame(['anna'], array_map(static fn (StandingEntryInput $entry): string => $entry->key, $round->entries));
        self::assertSame(['ben', 'cara'], $round->outsideKeys);
        self::assertTrue($round->counted);
        self::assertSame('r1', $round->roundId);
        self::assertSame(60, $round->minutesLimit);
    }

    public function testARoundWithNothingOfTheDivisionRecordedDoesNotCount(): void
    {
        $nobody = self::round('r1', [self::entry('ben', RoundEntryResult::finished(900), self::REGULAR)])->forDivision(self::SENIOR);
        self::assertFalse($nobody->counted);
        self::assertSame([], $nobody->entries);

        // Entered in the division, nothing recorded yet: not counted either (the organiser's "anything recorded")
        $waiting = self::round('r1', [
            self::entry('anna', RoundEntryResult::none(), self::SENIOR),
            self::entry('ben', RoundEntryResult::finished(900), self::REGULAR),
        ])->forDivision(self::SENIOR);
        self::assertFalse($waiting->counted);

        // A round that does not count stays so, whatever the division has
        $notCounted = new StandingRoundInput('r1', 60, false, [self::entry('anna', RoundEntryResult::finished(900), self::SENIOR)]);
        self::assertFalse($notCounted->forDivision(self::SENIOR)->counted);
    }

    public function testAnEntrantWhoseDivisionChangesCountsOnlyWhereItIsInIt(): void
    {
        $rounds = [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(1000), self::SENIOR), self::entry('cyril', RoundEntryResult::finished(1100), self::SENIOR)]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(1000), self::SENIOR), self::entry('cyril', RoundEntryResult::finished(1100), self::REGULAR)]),
        ];

        $rows = StandingCalculator::sum(new StandingRules(), array_map(static fn (StandingRoundInput $round): StandingRoundInput => $round->forDivision(self::SENIOR), $rounds));

        self::assertSame(['anna' => [1, 2000, true], 'cyril' => [null, 1100, false]], self::rows($rows));
        self::assertSame(StandingScoreStatus::NotInRound, $rows[1]->perRound['r2']->status);
    }

    public function testAnotherDivisionIsNeverANoShow(): void
    {
        $rules = new StandingRules(5, missing: StandingMissing::AsZeroPieces);
        $rounds = [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(1000), self::SENIOR), self::entry('cyril', RoundEntryResult::finished(1100), self::SENIOR)]),
            // Cyril played round 2 in Regular; Dora (Senior in round 1 below) did not come at all
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(1000), self::SENIOR), self::entry('cyril', RoundEntryResult::finished(1100), self::REGULAR)]),
        ];
        $rounds[0] = self::round('r1', [...$rounds[0]->entries, self::entry('dora', RoundEntryResult::finished(1200), self::SENIOR)]);

        $rows = StandingCalculator::sum($rules, array_map(static fn (StandingRoundInput $round): StandingRoundInput => $round->forDivision(self::SENIOR), $rounds));
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->key] = $row;
        }

        // Dora: a no-show in round 2 (60 min, no pieces known) - ranked; Cyril: not in the round - incomplete
        self::assertSame(StandingScoreStatus::NoShow, $byKey['dora']->perRound['r2']->status);
        self::assertTrue($byKey['dora']->complete);
        self::assertSame(StandingScoreStatus::NotInRound, $byKey['cyril']->perRound['r2']->status);
        self::assertFalse($byKey['cyril']->complete);
        self::assertSame(['anna' => [1, 2000, true], 'dora' => [2, 4800, true], 'cyril' => [null, 1100, false]], self::rows($rows));
    }

    public function testWithoutADivisionTheRoundIsAsBefore(): void
    {
        $round = self::round('r1', [self::entry('anna', RoundEntryResult::finished(1000), self::SENIOR), self::entry('ben', RoundEntryResult::finished(900), null)]);

        self::assertSame([], $round->outsideKeys);
        self::assertSame(['ben' => [1, 900, true], 'anna' => [2, 1000, true]], self::rows(StandingCalculator::sum(new StandingRules(), [$round])));
    }

    /**
     * @param list<StandingEntryInput> $entries
     */
    private static function round(string $id, array $entries): StandingRoundInput
    {
        return new StandingRoundInput($id, 60, StandingCalculator::anyRecorded($entries), $entries);
    }

    private static function entry(string $key, RoundEntryResult $result, null|string $divisionId): StandingEntryInput
    {
        return new StandingEntryInput(key: $key, result: $result, rank: null, piecesCount: null, name: $key, divisionId: $divisionId);
    }

    /**
     * @param list<StandingRow> $rows
     * @return array<string, array{null|int, int, bool}>
     */
    private static function rows(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->key] = [$row->rank, $row->totalSeconds, $row->complete];
        }

        return $byKey;
    }
}
