<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use Symfony\Component\Clock\MockClock;

/**
 * The as-of lookup of the difficulty indices: the pace row valid strictly before the solve in
 * (solved, tracked), else interpolated/extrapolated from the other piece counts' rows before it,
 * else the solver's first pace - when that is known at the moment the difficulty is computed for.
 */
final class PaceTimelineTest extends TestCase
{
    private const string PLAYER = 'player-a';
    private const float DAY = 86400.0;
    private const float EXPONENT = 1.3;

    public function testTheRowValidStrictlyBeforeTheSolveCounts(): void
    {
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY + 3600, 3000);
        $timeline->add(self::PLAYER, 500, 20 * self::DAY, 20 * self::DAY + 3600, 2400);

        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 15 * self::DAY, 15 * self::DAY));
        self::assertSame(2400, $timeline->paceAt(self::PLAYER, 500, 25 * self::DAY, 25 * self::DAY));

        // The row a solve wrote itself (same moment) is not before it
        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 20 * self::DAY, 20 * self::DAY + 3600));
    }

    public function testSameDaySolvesAreOrderedByTrackedAt(): void
    {
        // finished_at is a date: three rows on one day, apart only by tracked_at
        $day = 30 * self::DAY;
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, $day - self::DAY, $day - self::DAY, 3000);
        $timeline->add(self::PLAYER, 500, $day, $day + 9 * 3600, 2900);
        $timeline->add(self::PLAYER, 500, $day, $day + 18 * 3600, 2800);

        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, $day, $day + 8 * 3600));
        self::assertSame(2900, $timeline->paceAt(self::PLAYER, 500, $day, $day + 13 * 3600));
        self::assertSame(2800, $timeline->paceAt(self::PLAYER, 500, $day, $day + 20 * 3600));
    }

    public function testAnotherPieceCountIsExtrapolatedAndInterpolatedFromTheRowsBeforeTheSolve(): void
    {
        $calculator = $this->baselineCalculator();
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 3000);
        $timeline->add(self::PLAYER, 500, 40 * self::DAY, 40 * self::DAY, 2000);
        $timeline->add(self::PLAYER, 1000, 30 * self::DAY, 30 * self::DAY, 9000);

        // Before the 1000-piece pace existed: extrapolated from the 500-piece row of then, not of today
        self::assertSame(
            $calculator->extrapolateBaseline(1000, 500, 3000, self::EXPONENT),
            $timeline->paceAt(self::PLAYER, 1000, 20 * self::DAY, 20 * self::DAY),
        );

        // 750 between both, after both rows: interpolated from the rows valid then
        self::assertSame(
            $calculator->interpolateBaseline(750, 500, 2000, 1000, 9000),
            $timeline->paceAt(self::PLAYER, 750, 50 * self::DAY, 50 * self::DAY),
        );
        self::assertSame(
            $calculator->interpolateBaseline(750, 500, 3000, 1000, 9000),
            $timeline->paceAt(self::PLAYER, 750, 35 * self::DAY, 35 * self::DAY),
        );
    }

    public function testASolveBeforeTheSolverHadAnyPaceUsesTheirFirstPace(): void
    {
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 3000);
        $timeline->add(self::PLAYER, 500, 20 * self::DAY, 20 * self::DAY, 2400);

        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 2 * self::DAY, 2 * self::DAY));
        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY), 'The solve that formed the first pace');

        // At another piece count: the first pace, extrapolated
        self::assertSame(
            $this->baselineCalculator()->extrapolateBaseline(300, 500, 3000, self::EXPONENT),
            $timeline->paceAt(self::PLAYER, 300, 2 * self::DAY, 2 * self::DAY),
        );
    }

    public function testTheFirstPaceCountsOnlyWhenItIsKnownAtTheMomentTheDifficultyIsFor(): void
    {
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 3000);

        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 2 * self::DAY, 2 * self::DAY, knownBefore: [11 * self::DAY, 0.0]));
        self::assertNull($timeline->paceAt(self::PLAYER, 500, 2 * self::DAY, 2 * self::DAY, knownBefore: [10 * self::DAY, 10 * self::DAY]));
        self::assertNull($timeline->paceAt(self::PLAYER, 500, 2 * self::DAY, 2 * self::DAY, knownBefore: [5 * self::DAY, 5 * self::DAY]));

        // A row before the solve needs no first pace, so knownBefore changes nothing for it
        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 12 * self::DAY, 12 * self::DAY, knownBefore: [12 * self::DAY, 13 * self::DAY]));
    }

    public function testOfTwoRowsOfTheSameMomentTheLaterCounts(): void
    {
        // Two solves logged in the same second, loaded in qualifying_count order
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 3000);
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 2900);

        self::assertSame(2900, $timeline->paceAt(self::PLAYER, 500, 11 * self::DAY, 11 * self::DAY));

        // ... also when they arrive out of order and get sorted
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 20 * self::DAY, 20 * self::DAY, 2500);
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 3000);
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 2900);

        self::assertSame(2900, $timeline->paceAt(self::PLAYER, 500, 11 * self::DAY, 11 * self::DAY));
    }

    public function testAZeroPaceTakesNoPartInInterpolation(): void
    {
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 0);
        $timeline->add(self::PLAYER, 300, 10 * self::DAY, 10 * self::DAY + 60, 1800);

        self::assertSame(
            $this->baselineCalculator()->extrapolateBaseline(1000, 300, 1800, self::EXPONENT),
            $timeline->paceAt(self::PLAYER, 1000, 20 * self::DAY, 20 * self::DAY),
        );
    }

    public function testAPlayerWithoutRowsHasNoPace(): void
    {
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 3000);

        self::assertNull($timeline->paceAt('player-b', 500, 20 * self::DAY, 20 * self::DAY));
    }

    public function testRowsAddedOutOfOrderAndTwiceAreSortedAndCountedOnce(): void
    {
        $timeline = $this->timeline();
        $timeline->add(self::PLAYER, 500, 20 * self::DAY, 20 * self::DAY, 2400);
        $timeline->add(self::PLAYER, 500, 10 * self::DAY, 10 * self::DAY, 3000);
        $timeline->add(self::PLAYER, 500, 20 * self::DAY, 20 * self::DAY, 2400);
        $timeline->add(self::PLAYER, 500, 30 * self::DAY, 30 * self::DAY, 2000);

        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 15 * self::DAY, 15 * self::DAY));
        self::assertSame(2400, $timeline->paceAt(self::PLAYER, 500, 25 * self::DAY, 25 * self::DAY));
        self::assertSame(2000, $timeline->paceAt(self::PLAYER, 500, 35 * self::DAY, 35 * self::DAY));
        self::assertSame(3000, $timeline->paceAt(self::PLAYER, 500, 5 * self::DAY, 5 * self::DAY));
    }

    private function timeline(): PaceTimeline
    {
        return new PaceTimeline($this->baselineCalculator(), self::EXPONENT);
    }

    private function baselineCalculator(): PlayerBaselineCalculator
    {
        return new PlayerBaselineCalculator($this->createStub(Connection::class), new MockClock(TestClock::now()));
    }
}
