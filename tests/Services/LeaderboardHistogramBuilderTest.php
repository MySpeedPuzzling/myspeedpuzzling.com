<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\LeaderboardHistogram;
use SpeedPuzzling\Web\Services\LeaderboardHistogramBuilder;

final class LeaderboardHistogramBuilderTest extends TestCase
{
    public function testNoRowsGiveAnEmptyHistogram(): void
    {
        $histogram = new LeaderboardHistogramBuilder()->build([]);

        self::assertSame([], $histogram->bins);
        self::assertSame(0, $histogram->total);
        self::assertNull($histogram->medianTime);
        self::assertNull($histogram->medianPosition);
    }

    public function testSingleSolverIsOneBar(): void
    {
        $histogram = new LeaderboardHistogramBuilder()->build([3605], viewerTime: 3605);

        self::assertCount(1, $histogram->bins);
        self::assertSame(10, $histogram->binWidth);
        self::assertSame(3600, $histogram->bins[0]->from);
        self::assertSame(3610, $histogram->bins[0]->to);
        self::assertSame(1, $histogram->bins[0]->count);
        self::assertSame(3605, $histogram->medianTime);
        self::assertSame(0.5, $histogram->medianPosition);
        self::assertSame(0, $histogram->viewerBin);
        self::assertSame(0.5, $histogram->viewerPosition);
    }

    public function testIdenticalTimesShareOneBar(): void
    {
        $histogram = new LeaderboardHistogramBuilder()->build(array_fill(0, 5, 2000));

        self::assertCount(1, $histogram->bins);
        self::assertSame(5, $histogram->bins[0]->count);
        self::assertSame(2000, $histogram->medianTime);
        self::assertSame(0.0, $histogram->medianPosition);
    }

    public function testNarrowestNiceWidthThatFitsAndBarsStartOnItsMultiples(): void
    {
        // 301 solvers spread evenly from 25 to 50 minutes: 60 s bars are the narrowest within 30 bars
        $times = range(1500, 3000, 5);

        $histogram = new LeaderboardHistogramBuilder()->build($times);

        self::assertSame(60, $histogram->binWidth);
        self::assertCount(26, $histogram->bins);
        self::assertSame(1500, $histogram->bins[0]->from);

        foreach ($histogram->bins as $bin) {
            self::assertFalse($bin->isFoldedTail());
            self::assertSame(0, ($bin->from ?? 0) % 60);
        }

        self::assertSame(301, self::countedRows($histogram));
        // Median 2250 s is 750 s = 12.5 bars after the first bar starts
        self::assertSame(2250, $histogram->medianTime);
        self::assertSame(12.5, $histogram->medianPosition);
    }

    public function testFarSlowOutlierFoldsIntoAnOpenLastBar(): void
    {
        // 100 solvers between 50 and 67 minutes, one who took 10 hours
        $times = [...range(3000, 3990, 10), 36000];

        $histogram = new LeaderboardHistogramBuilder()->build($times, viewerTime: 36000);

        self::assertSame(60, $histogram->binWidth);
        self::assertCount(18, $histogram->bins);
        $last = $histogram->bins[17];
        self::assertSame(4020, $last->from);
        self::assertNull($last->to);
        self::assertSame(1, $last->count);
        self::assertSame(101, self::countedRows($histogram));

        // The viewer in the folded tail sits in the middle of that bar
        self::assertSame(17, $histogram->viewerBin);
        self::assertSame(17.5, $histogram->viewerPosition);
    }

    public function testFarFastOutlierFoldsIntoAnOpenFirstBar(): void
    {
        // 100 solvers between 50 and 67 minutes, one entry of one minute
        $times = [60, ...range(3000, 3990, 10)];

        $histogram = new LeaderboardHistogramBuilder()->build($times, viewerTime: 3000);
        $first = $histogram->bins[0];

        self::assertNull($first->from);
        self::assertSame(3000, $first->to);
        self::assertSame(1, $first->count);
        self::assertCount(18, $histogram->bins);

        // Everything after the open bar moves one bar to the right
        self::assertSame(1, $histogram->viewerBin);
        self::assertSame(1.0, $histogram->viewerPosition);
        self::assertSame(3490, $histogram->medianTime);
        self::assertEqualsWithDelta(1 + 490 / 60, $histogram->medianPosition, 0.0001);
    }

    public function testFirstAttemptsAreCountedPerBarTailsIncluded(): void
    {
        // 100 solvers between 50 and 67 minutes plus a 10-hour one; the ten fastest and the 10-hour one were first attempts
        $times = [...range(3000, 3990, 10), 36000];
        $firstAttemptTimes = [...range(3000, 3090, 10), 36000];

        $histogram = new LeaderboardHistogramBuilder()->build($times, firstAttemptTimes: $firstAttemptTimes);

        // 60 s bars: 3000-3059 holds six first attempts, 3060-3119 the other four
        self::assertSame(6, $histogram->bins[0]->firstAttempts);
        self::assertSame(0, $histogram->bins[0]->repeats());
        self::assertSame(4, $histogram->bins[1]->firstAttempts);
        self::assertSame(2, $histogram->bins[1]->repeats());
        self::assertSame(1, $histogram->bins[17]->firstAttempts);
        self::assertNull($histogram->bins[17]->to);
        self::assertSame(11, array_sum(array_map(static fn ($bin): int => $bin->firstAttempts, $histogram->bins)));
    }

    public function testWithoutFirstAttemptTimesEveryRowIsARepeat(): void
    {
        $histogram = new LeaderboardHistogramBuilder()->build([100, 200, 300]);

        foreach ($histogram->bins as $bin) {
            self::assertSame(0, $bin->firstAttempts);
            self::assertSame($bin->count, $bin->repeats());
        }
    }

    public function testMedianOfAnEvenCountIsTheAverageOfTheMiddleTwo(): void
    {
        $histogram = new LeaderboardHistogramBuilder()->build([400, 100, 300, 200]);

        self::assertSame(250, $histogram->medianTime);
    }

    public function testViewerWithoutARowIsNotMarked(): void
    {
        $histogram = new LeaderboardHistogramBuilder()->build([100, 200, 300]);

        self::assertNull($histogram->viewerTime);
        self::assertNull($histogram->viewerBin);
        self::assertNull($histogram->viewerPosition);
    }

    public function testBarLimitGrowsWithTheLeaderboard(): void
    {
        self::assertSame(5, LeaderboardHistogramBuilder::maxBins(0));
        self::assertSame(5, LeaderboardHistogramBuilder::maxBins(2));
        self::assertSame(6, LeaderboardHistogramBuilder::maxBins(5));
        self::assertSame(8, LeaderboardHistogramBuilder::maxBins(10));
        self::assertSame(16, LeaderboardHistogramBuilder::maxBins(50));
        self::assertSame(20, LeaderboardHistogramBuilder::maxBins(100));
        self::assertSame(30, LeaderboardHistogramBuilder::maxBins(225));
        self::assertSame(30, LeaderboardHistogramBuilder::maxBins(1800));
    }

    public function testAHandfulOfPuzzlersGetsAFewWideBars(): void
    {
        // Five puzzlers between 32 and 81 minutes: the 30-bar limit gave 2-minute bars, 26 slots for five people
        $histogram = new LeaderboardHistogramBuilder()->build([1920, 2580, 3010, 3900, 4860]);

        self::assertSame(600, $histogram->binWidth);
        self::assertCount(6, $histogram->bins);
        self::assertSame(1800, $histogram->bins[0]->from);
        self::assertSame(5, self::countedRows($histogram));
    }

    public function testMonsterPuzzlesGetHourLongBars(): void
    {
        // 20 to 100 hours
        $times = range(72000, 360000, 3600);

        $histogram = new LeaderboardHistogramBuilder()->build($times);

        self::assertSame(14400, $histogram->binWidth);
        self::assertLessThanOrEqual(LeaderboardHistogramBuilder::MAX_BINS, count($histogram->bins));
        self::assertSame(count($times), self::countedRows($histogram));
    }

    public function testSkewedLeaderboardsNeverExceedTheBarLimitAndLoseNoRow(): void
    {
        mt_srand(20260930);

        foreach ([51, 150, 800, 1800] as $size) {
            // Right-skewed like real leaderboards: most around an hour, a long slow tail, a few outliers
            $times = [];

            for ($i = 0; $i < $size; $i++) {
                $times[] = (int) (1200 + 3600 * exp(mt_rand(-900, 900) / 1000) * (mt_rand(0, 99) === 0 ? 8 : 1));
            }

            $histogram = new LeaderboardHistogramBuilder()->build($times, viewerTime: $times[0]);

            $coreBars = array_filter($histogram->bins, static fn ($bin): bool => $bin->isFoldedTail() === false);
            self::assertLessThanOrEqual(LeaderboardHistogramBuilder::maxBins($size), count($coreBars), "size {$size}");
            self::assertSame($size, self::countedRows($histogram), "size {$size}");
            $viewerBin = $histogram->viewerBin;
            self::assertNotNull($viewerBin);
            self::assertArrayHasKey($viewerBin, $histogram->bins);
            self::assertGreaterThan(0, $histogram->bins[$viewerBin]->count);
        }
    }

    private static function countedRows(LeaderboardHistogram $histogram): int
    {
        return array_sum(array_map(static fn ($bin): int => $bin->count, $histogram->bins));
    }
}
