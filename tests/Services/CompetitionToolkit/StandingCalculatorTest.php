<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Services\CompetitionToolkit\StandingCalculator;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\StandingEntrant;
use SpeedPuzzling\Web\Value\StandingEntryInput;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRoundInput;
use SpeedPuzzling\Web\Value\StandingRules;
use SpeedPuzzling\Web\Value\StandingScoreStatus;

/**
 * The `sum` rule of the standings (competition toolkit A6, A-round-formats.md §3.3).
 */
final class StandingCalculatorTest extends TestCase
{
    public function testFinishedTimesAddUpAndTheLowestTotalWins(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('ben', RoundEntryResult::finished(2900))]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('ben', RoundEntryResult::finished(3200))]),
        ]);

        self::assertSame(['anna' => [1, 6000], 'ben' => [2, 6100]], self::ranks($rows));
        self::assertTrue($rows[0]->complete);
        self::assertSame(2, $rows[0]->roundsFinished);
        self::assertSame(2, $rows[0]->roundsScored);
        self::assertSame(StandingScoreStatus::Finished, $rows[1]->perRound['r1']->status);
        self::assertSame(2900, $rows[1]->perRound['r1']->scoreSeconds);
    }

    public function testEqualTotalsShareARankAndTheNextRankSkips(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [
                self::entry('anna', RoundEntryResult::finished(100)),
                self::entry('ben', RoundEntryResult::finished(200)),
                self::entry('cara', RoundEntryResult::finished(200)),
                self::entry('dan', RoundEntryResult::finished(300)),
            ]),
        ]);

        self::assertSame(['anna' => [1, 100], 'ben' => [2, 200], 'cara' => [2, 200], 'dan' => [4, 300]], self::ranks($rows));
    }

    public function testATieIsListedByNameWithoutAHiddenTieBreak(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('k2', RoundEntryResult::finished(500), name: 'Zoe'), self::entry('k1', RoundEntryResult::finished(400), name: 'Yan')]),
            self::round('r2', [self::entry('k2', RoundEntryResult::finished(400), name: 'Zoe'), self::entry('k1', RoundEntryResult::finished(500), name: 'Yan')]),
        ]);

        self::assertSame(['Yan', 'Zoe'], array_map(static fn (StandingRow $row): string => $row->name, $rows));
        self::assertSame([1, 1], array_map(static fn (StandingRow $row): null|int => $row->rank, $rows));
    }

    public function testAnUnfinishedRoundScoresTheTimeLimitPlusThePenaltyPerMissingPiece(): void
    {
        $rounds = [self::round('r1', [
            self::entry('anna', RoundEntryResult::unfinished(450), pieces: 500),
            self::entry('ben', RoundEntryResult::finished(5000), pieces: 500),
        ], minutesLimit: 90)];

        $noPenalty = StandingCalculator::sum(new StandingRules(), $rounds);
        self::assertSame(['ben' => [1, 5000], 'anna' => [2, 5400]], self::ranks($noPenalty));
        self::assertSame(StandingScoreStatus::Unfinished, $noPenalty[1]->perRound['r1']->status);
        self::assertSame(0, $noPenalty[1]->roundsFinished);
        self::assertSame(1, $noPenalty[1]->roundsScored);

        // 50 pieces missing × 5 s = 250 s on top of 90 minutes
        $penalty = StandingCalculator::sum(new StandingRules(5, StandingOrder::TotalOnly), $rounds);
        self::assertSame(5650, $penalty[1]->totalSeconds);
    }

    public function testUnknownPiecesAddNoPenaltyAndMorePiecesThanTheTotalNeverGoBelowTheLimit(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(10), [self::round('r1', [
            self::entry('unknown', RoundEntryResult::unfinished(200), pieces: null),
            self::entry('over', RoundEntryResult::unfinished(600), pieces: 500),
        ], minutesLimit: 60)]);

        self::assertSame([3600, 3600], array_map(static fn (StandingRow $row): int => $row->totalSeconds, $rows));
    }

    public function testAnUnfinishedResultInARoundWithoutALimitIsAMissingRound(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(1000)), self::entry('ben', RoundEntryResult::finished(1100))]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(1000)), self::entry('ben', RoundEntryResult::unfinished(300), pieces: 500)], minutesLimit: null),
        ]);

        self::assertSame(['anna' => [1, 2000], 'ben' => [null, 1100]], self::ranks($rows));
        self::assertFalse($rows[1]->complete);
        self::assertSame(StandingScoreStatus::UnfinishedWithoutLimit, $rows[1]->perRound['r2']->status);
        self::assertNull($rows[1]->perRound['r2']->scoreSeconds);
    }

    public function testDidNotStartNoResultAndNotEnteredAreMissingRoundsListedAfterEverybodyWithoutARank(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [
                self::entry('slow', RoundEntryResult::finished(9000)),
                self::entry('dns', RoundEntryResult::finished(1000)),
                self::entry('pending', RoundEntryResult::finished(1000)),
                self::entry('absent', RoundEntryResult::finished(500)),
            ]),
            self::round('r2', [
                self::entry('slow', RoundEntryResult::finished(9000)),
                self::entry('dns', RoundEntryResult::didNotStart()),
                self::entry('pending', RoundEntryResult::none()),
            ]),
        ]);

        // The complete entrant first, ranked; the three incomplete after, unranked, by their total
        self::assertSame(['slow' => [1, 18000], 'absent' => [null, 500], 'dns' => [null, 1000], 'pending' => [null, 1000]], self::ranks($rows));
        $byKey = self::byKey($rows);
        self::assertSame(StandingScoreStatus::DidNotStart, $byKey['dns']->perRound['r2']->status);
        self::assertSame(StandingScoreStatus::NoResult, $byKey['pending']->perRound['r2']->status);
        self::assertSame(StandingScoreStatus::NotInRound, $byKey['absent']->perRound['r2']->status);
        self::assertNull($byKey['absent']->perRound['r2']->result);
        self::assertTrue(StandingScoreStatus::NotInRound->isMissing());
    }

    public function testIncompleteEntrantsAreOrderedByRoundsScoredFirst(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('two', RoundEntryResult::finished(5000)), self::entry('one', RoundEntryResult::finished(100)), self::entry('all', RoundEntryResult::finished(9999))]),
            self::round('r2', [self::entry('two', RoundEntryResult::finished(5000)), self::entry('all', RoundEntryResult::finished(9999))]),
            self::round('r3', [self::entry('all', RoundEntryResult::finished(9999))]),
        ]);

        self::assertSame(['all', 'two', 'one'], array_map(static fn (StandingRow $row): string => $row->key, $rows));
        self::assertSame([1, null, null], array_map(static fn (StandingRow $row): null|int => $row->rank, $rows));
    }

    public function testAnEntrantWithoutAnyScoreIsLeftOut(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(100)), self::entry('ghost', RoundEntryResult::didNotStart()), self::entry('new', RoundEntryResult::none())]),
        ]);

        self::assertSame(['anna'], array_map(static fn (StandingRow $row): string => $row->key, $rows));
    }

    public function testFinishedRoundsFirstPutsCompletionBeforeTheTotalAndTotalOnlyDoesNot(): void
    {
        // Fast but did not finish round 2 (90 min limit) vs slow but finished both
        $rounds = [
            self::round('r1', [self::entry('fast', RoundEntryResult::finished(1800)), self::entry('steady', RoundEntryResult::finished(5000))]),
            self::round('r2', [self::entry('fast', RoundEntryResult::unfinished(999), pieces: 1000), self::entry('steady', RoundEntryResult::finished(5300))]),
        ];

        $default = StandingCalculator::sum(new StandingRules(), $rounds);
        self::assertSame(['steady' => [1, 10300], 'fast' => [2, 7200]], self::ranks($default));

        $totalOnly = StandingCalculator::sum(new StandingRules(0, StandingOrder::TotalOnly), $rounds);
        self::assertSame(['fast' => [1, 7200], 'steady' => [2, 10300]], self::ranks($totalOnly));

        // With 5 s per missing piece the 1 missing piece adds 5 s - total only still puts "fast" first
        self::assertSame(7205, StandingCalculator::sum(new StandingRules(5, StandingOrder::TotalOnly), $rounds)[0]->totalSeconds);
    }

    public function testFinishedRoundsFirstRanksEqualFinishesAndTotalsTogether(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('a', RoundEntryResult::unfinished(10), pieces: 100), self::entry('b', RoundEntryResult::finished(3600)), self::entry('c', RoundEntryResult::finished(1000))]),
            self::round('r2', [self::entry('a', RoundEntryResult::finished(1000)), self::entry('b', RoundEntryResult::unfinished(90), pieces: 100), self::entry('c', RoundEntryResult::finished(1000))]),
        ]);

        // c finished both; a and b one each, then by total (5400 + 1000 vs 3600 + 5400)
        self::assertSame(['c' => [1, 2000], 'a' => [2, 6400], 'b' => [3, 9000]], self::ranks($rows));
    }

    public function testRoundsThatDoNotCountScoreNobodyAndMakeNobodyMissing(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(100)), self::entry('ben', RoundEntryResult::finished(200))]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(1)), self::entry('ben', RoundEntryResult::none())], counted: false),
        ]);

        self::assertSame(['anna' => [1, 100], 'ben' => [2, 200]], self::ranks($rows));
        self::assertTrue($rows[1]->complete);
        self::assertSame(StandingScoreStatus::NotCounted, $rows[0]->perRound['r2']->status);
        self::assertNull($rows[0]->perRound['r2']->result);
        self::assertFalse(StandingScoreStatus::NotCounted->isMissing());
        self::assertSame(['r1', 'r2'], array_keys($rows[0]->perRound));
    }

    public function testNoCountedRoundGivesNoRows(): void
    {
        self::assertSame([], StandingCalculator::sum(new StandingRules(), []));
        self::assertSame([], StandingCalculator::sum(new StandingRules(), [self::round('r1', [self::entry('a', RoundEntryResult::finished(1))], counted: false)]));
    }

    public function testTheRoundRankIsCarriedButIsNoRankingInput(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(100), rank: 7)]),
        ]);

        self::assertSame(7, $rows[0]->perRound['r1']->rank);
        self::assertSame(1, $rows[0]->rank);
    }

    public function testPairsAreTheSameEntrantAcrossRoundsOnlyWithExactlyTheSamePeople(): void
    {
        $event = '018d0099-0000-0000-0000-000000000001';
        $annaBen = CompetitionLineupId::of($event, ['p-anna', 'p-ben']);
        // The same people listed the other way round, upper-cased - the same line-up
        $benAnna = CompetitionLineupId::of($event, ['P-BEN', 'p-anna']);
        // Ben replaced by a substitute - another line-up
        $annaSub = CompetitionLineupId::of($event, ['p-anna', 'p-sub']);
        self::assertSame($annaBen, $benAnna);

        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry($annaBen, RoundEntryResult::finished(1000), name: 'Sharks')]),
            self::round('r2', [self::entry($benAnna, RoundEntryResult::finished(1100), name: 'Sharks')]),
            self::round('r3', [self::entry($annaSub, RoundEntryResult::finished(900), name: 'Sharks')]),
        ]);

        $byKey = self::byKey($rows);
        self::assertCount(2, $rows);
        self::assertSame(2, $byKey[$annaBen]->roundsScored);
        self::assertSame(2100, $byKey[$annaBen]->totalSeconds);
        self::assertFalse($byKey[$annaBen]->complete);
        self::assertSame(1, $byKey[$annaSub]->roundsScored);
        self::assertSame(StandingScoreStatus::NotInRound, $byKey[$annaSub]->perRound['r1']->status);
    }

    public function testTheNameAndPeopleComeFromTheLastRoundTheEntrantIsIn(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [self::entry('pair', RoundEntryResult::finished(1000), name: 'Old name')]),
            self::round('r2', [new StandingEntryInput('pair', RoundEntryResult::finished(1000), 1, null, 'New name', ['cz'], [new StandingEntrant('Anna'), new StandingEntrant('Ben')])]),
            self::round('r3', []),
        ]);

        self::assertSame('New name', $rows[0]->name);
        self::assertSame(['cz'], $rows[0]->countries);
        self::assertCount(2, $rows[0]->entrants);
    }

    public function testTheSamePeopleTwiceInOneRoundCountWithTheBetterResult(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(), [
            self::round('r1', [
                self::entry('pair', RoundEntryResult::didNotStart()),
                self::entry('pair', RoundEntryResult::finished(1200)),
                self::entry('pair', RoundEntryResult::finished(1500)),
            ]),
        ]);

        self::assertSame(1200, $rows[0]->totalSeconds);
    }

    public function testAnyRecorded(): void
    {
        self::assertFalse(StandingCalculator::anyRecorded([]));
        self::assertFalse(StandingCalculator::anyRecorded([self::entry('a', RoundEntryResult::none())]));
        self::assertTrue(StandingCalculator::anyRecorded([self::entry('a', RoundEntryResult::none()), self::entry('b', RoundEntryResult::didNotStart())]));
    }

    /**
     * Competition toolkit T6 - Jan's World Series: two rounds, 30 s per missing piece. The total = the times + the
     * penalty; the missing pieces and the parts are kept per round and per row.
     */
    public function testUnfinishedRoundsShowTimeMissingPiecesAndThePenalisedTotal(): void
    {
        $rounds = [
            self::round('r1', [self::entry('anna', RoundEntryResult::unfinished(463)), self::entry('ben', RoundEntryResult::finished(5000))], minutesLimit: 90),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(4000)), self::entry('ben', RoundEntryResult::unfinished(499))], minutesLimit: 90),
        ];
        $rows = StandingCalculator::sum(new StandingRules(30, StandingOrder::TotalOnly), $rounds);

        // anna: 5400 + 4000 = 9400 time, 37 missing × 30 = 1110 → 10510; ben: 5000 + 5400 = 10400, 1 × 30 → 10430
        self::assertSame(['ben' => [1, 10430], 'anna' => [2, 10510]], self::ranks($rows));
        self::assertSame([10400, 1, 30], [$rows[0]->totalTimeSeconds, $rows[0]->totalMissingPieces, $rows[0]->totalPenaltySeconds]);
        self::assertSame([9400, 37, 1110], [$rows[1]->totalTimeSeconds, $rows[1]->totalMissingPieces, $rows[1]->totalPenaltySeconds]);
        $annaR1 = $rows[1]->perRound['r1'];
        self::assertSame([5400, 37, 1110, 6510], [$annaR1->timeSeconds, $annaR1->missingPieces, $annaR1->penaltySeconds, $annaR1->scoreSeconds]);

        // Penalty 0: the missing pieces are shown, the order is by time alone
        $free = StandingCalculator::sum(new StandingRules(0, StandingOrder::TotalOnly), $rounds);
        self::assertSame(['anna' => [1, 9400], 'ben' => [2, 10400]], self::ranks($free));
        self::assertSame(37, $free[0]->totalMissingPieces);
        self::assertSame(0, $free[0]->totalPenaltySeconds);
    }

    public function testPiecesMissingFromAFinishedPuzzleArePenalisedOnlyWithTheSwitch(): void
    {
        $rounds = [
            self::round('r1', [
                new StandingEntryInput('anna', RoundEntryResult::finished(3000), null, 500, 'anna', missingPieces: 2),
                self::entry('ben', RoundEntryResult::finished(3050)),
            ]),
        ];

        $off = StandingCalculator::sum(new StandingRules(30), $rounds);
        self::assertSame(['anna' => [1, 3000], 'ben' => [2, 3050]], self::ranks($off));
        self::assertSame(StandingScoreStatus::Finished, $off[0]->perRound['r1']->status);
        self::assertTrue($off[0]->perRound['r1']->isFinishedWithMissingPieces());
        self::assertSame([3000, 2, 0], [$off[0]->totalTimeSeconds, $off[0]->totalMissingPieces, $off[0]->totalPenaltySeconds]);

        $on = StandingCalculator::sum(new StandingRules(30, penaliseFinishedMissing: true), $rounds);
        self::assertSame(['ben' => [1, 3050], 'anna' => [2, 3060]], self::ranks($on));
        self::assertSame([3000, 2, 60], [$on[1]->totalTimeSeconds, $on[1]->totalMissingPieces, $on[1]->totalPenaltySeconds]);
        // Still a finished round for "more finished rounds first"
        self::assertSame(1, $on[1]->roundsFinished);
    }

    public function testEqualPenalisedTotalsShareARank(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(10, penaliseFinishedMissing: true), [
            self::round('r1', [
                new StandingEntryInput('anna', RoundEntryResult::finished(1000), null, 500, 'anna', missingPieces: 1),
                self::entry('ben', RoundEntryResult::finished(1010)),
            ]),
        ]);

        self::assertSame(['anna' => [1, 1010], 'ben' => [1, 1010]], self::ranks($rows));
    }

    public function testAsZeroPiecesScoresANoShowAsTheLimitPlusThePenaltyForAllPiecesAndRanksIt(): void
    {
        $rules = new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces);
        $rows = StandingCalculator::sum($rules, [
            self::round('r1', [
                self::entry('anna', RoundEntryResult::finished(3000)),
                self::entry('dns', RoundEntryResult::finished(2000)),
                self::entry('pending', RoundEntryResult::finished(2500)),
                self::entry('absent', RoundEntryResult::finished(1500)),
            ], noShowPieces: 500),
            self::round('r2', [
                self::entry('anna', RoundEntryResult::finished(3000)),
                self::entry('dns', RoundEntryResult::didNotStart()),
                self::entry('pending', RoundEntryResult::none()),
            ], noShowPieces: 1000),
        ]);

        // 90 min + 5 s × 1000 pieces = 5400 + 5000 for each kind of no-show; everybody complete and ranked
        self::assertSame(['anna' => [1, 6000], 'absent' => [2, 11900], 'dns' => [3, 12400], 'pending' => [4, 12900]], self::ranks($rows));
        $absent = self::byKey($rows)['absent'];
        self::assertTrue($absent->complete);
        self::assertSame(2, $absent->roundsScored);
        self::assertSame(1, $absent->roundsFinished);
        $noShow = $absent->perRound['r2'];
        self::assertSame(StandingScoreStatus::NoShow, $noShow->status);
        self::assertSame([10400, 5400, 1000, 5000], [$noShow->scoreSeconds, $noShow->timeSeconds, $noShow->missingPieces, $noShow->penaltySeconds]);
        self::assertNull($noShow->result);
        self::assertSame('no_show', $noShow->toArray()['status']);
        self::assertSame(StandingScoreStatus::NoShow, self::byKey($rows)['dns']->perRound['r2']->status);
        self::assertSame([6900, 1000, 5000], [$absent->totalTimeSeconds, $absent->totalMissingPieces, $absent->totalPenaltySeconds]);
    }

    public function testAsZeroPiecesWaitsForAFinalRoundExceptForAnExplicitDidNotStart(): void
    {
        $rules = new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces);
        $rows = StandingCalculator::sum($rules, [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('dns', RoundEntryResult::finished(2000)), self::entry('pending', RoundEntryResult::finished(1000)), self::entry('absent', RoundEntryResult::finished(1500))]),
            // Still being recorded (organiser preview of an unpublished round): no result = not yet, did not start = no-show
            self::round('r2', [
                self::entry('anna', RoundEntryResult::finished(3000)),
                self::entry('dns', RoundEntryResult::didNotStart()),
                self::entry('pending', RoundEntryResult::none()),
            ], final: false, noShowPieces: 500),
        ]);

        self::assertSame(['anna' => [1, 6000], 'dns' => [2, 9900], 'pending' => [null, 1000], 'absent' => [null, 1500]], self::ranks($rows));
        $byKey = self::byKey($rows);
        self::assertSame(StandingScoreStatus::NoResult, $byKey['pending']->perRound['r2']->status);
        self::assertSame(StandingScoreStatus::NotInRound, $byKey['absent']->perRound['r2']->status);
        self::assertSame(StandingScoreStatus::NoShow, $byKey['dns']->perRound['r2']->status);
    }

    public function testAsZeroPiecesNeedsATimeLimitAndNeverScoresAPairOrTeamWithoutPeople(): void
    {
        $rules = new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces);
        $rows = StandingCalculator::sum($rules, [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('ben', RoundEntryResult::finished(2000)), self::entry('t:team', RoundEntryResult::finished(1000))]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(3000))], minutesLimit: null, noShowPieces: 500),
            self::round('r3', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('ben', RoundEntryResult::finished(2000))], noShowPieces: 500),
        ]);

        $byKey = self::byKey($rows);
        // No limit in r2: Ben stays missing there; the team without people is missing in r2 and r3
        self::assertSame(StandingScoreStatus::NotInRound, $byKey['ben']->perRound['r2']->status);
        self::assertFalse($byKey['ben']->complete);
        self::assertSame(StandingScoreStatus::NotInRound, $byKey['t:team']->perRound['r3']->status);
        self::assertSame(1, $byKey['t:team']->roundsScored);
        self::assertSame(['anna' => [1, 9000], 'ben' => [null, 4000], 't:team' => [null, 1000]], self::ranks($rows));
    }

    public function testAsZeroPiecesLeavesOutAnEntrantWhoseEveryScoreIsANoShow(): void
    {
        $rules = new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces);
        $rows = StandingCalculator::sum($rules, [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('ghost', RoundEntryResult::didNotStart())]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('ghost', RoundEntryResult::none())]),
        ]);

        self::assertSame(['anna'], array_keys(self::byKey($rows)));
    }

    public function testAsZeroPiecesWithUnknownPiecesScoresJustTheLimit(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(5, StandingOrder::TotalOnly, StandingMissing::AsZeroPieces), [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(3000)), self::entry('ben', RoundEntryResult::finished(2000))]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(3000))], minutesLimit: 60, noShowPieces: null),
        ]);

        self::assertSame(['ben' => [1, 5600], 'anna' => [2, 6000]], self::ranks($rows));
    }

    public function testFinishedRoundsFirstPutsANoShowBehindEveryFinisher(): void
    {
        $rows = StandingCalculator::sum(new StandingRules(0, StandingOrder::FinishedRoundsFirst, StandingMissing::AsZeroPieces), [
            self::round('r1', [self::entry('anna', RoundEntryResult::finished(5000)), self::entry('ben', RoundEntryResult::finished(100))]),
            self::round('r2', [self::entry('anna', RoundEntryResult::finished(5000))], minutesLimit: 1),
        ]);

        self::assertSame(['anna' => [1, 10000], 'ben' => [2, 160]], self::ranks($rows));
    }

    public function testTheRulesRefuseAPenaltyOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StandingRules(601);
    }

    /**
     * @param list<StandingEntryInput> $entries
     */
    private static function round(string $id, array $entries, null|int $minutesLimit = 90, bool $counted = true, bool $final = true, null|int $noShowPieces = null): StandingRoundInput
    {
        return new StandingRoundInput($id, $minutesLimit, $counted, $entries, $final, $noShowPieces);
    }

    private static function entry(string $key, RoundEntryResult $result, null|int $pieces = 500, null|int $rank = null, null|string $name = null): StandingEntryInput
    {
        return new StandingEntryInput($key, $result, $rank, $pieces, $name ?? $key);
    }

    /**
     * @param list<StandingRow> $rows
     * @return array<string, array{null|int, int}> key => [rank, total], in the standing's order
     */
    private static function ranks(array $rows): array
    {
        $ranks = [];
        foreach ($rows as $row) {
            $ranks[$row->key] = [$row->rank, $row->totalSeconds];
        }

        return $ranks;
    }

    /**
     * @param list<StandingRow> $rows
     * @return array<string, StandingRow>
     */
    private static function byKey(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->key] = $row;
        }

        return $byKey;
    }
}
