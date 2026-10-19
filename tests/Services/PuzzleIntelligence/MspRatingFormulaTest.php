<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;

final class MspRatingFormulaTest extends TestCase
{
    /**
     * The binary search must give exactly what the linear scan it replaced gave - every stored percentile and every
     * what-if depends on it.
     */
    public function testBinarySearchPercentileEqualsTheLinearScan(): void
    {
        mt_srand(42);

        for ($round = 0; $round < 300; $round++) {
            $times = [];
            $count = mt_rand(1, 60);

            for ($i = 0; $i < $count; $i++) {
                // Few distinct values: plenty of ties
                $times[] = 1000 + 10 * mt_rand(0, 15);
            }

            sort($times);

            foreach (array_unique($times) as $time) {
                self::assertSame(self::linearPercentile($time, $times), MspRatingFormula::percentileInSortedTimes($time, $times));
            }
        }
    }

    public function testPercentileEnds(): void
    {
        self::assertSame(0.5, MspRatingFormula::percentileInSortedTimes(100, [100]));
        self::assertSame(1.0, MspRatingFormula::percentileInSortedTimes(100, [100, 200, 300]));
        self::assertSame(0.0, MspRatingFormula::percentileInSortedTimes(300, [100, 200, 300]));
        self::assertSame(0.5, MspRatingFormula::percentileInSortedTimes(200, [100, 200, 300]));
        // Tied with one other: half a place
        self::assertSame(0.75, MspRatingFormula::percentileInSortedTimes(100, [100, 100, 300]));
    }

    public function testDifficultyWeightBlendsWithConfidence(): void
    {
        self::assertSame(1.0, MspRatingFormula::difficultyWeight(1.4, 0));
        self::assertEqualsWithDelta(1.2, MspRatingFormula::difficultyWeight(1.4, 50), 1e-12);
        self::assertEqualsWithDelta(1.2, MspRatingFormula::difficultyWeight(1.4, 500), 1e-12);
        self::assertEqualsWithDelta(1.1, MspRatingFormula::difficultyWeight(1.4, 25), 1e-12);
    }

    public function testDecayHasAThreeMonthPlateau(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');

        self::assertSame(1.0, MspRatingFormula::decay(new DateTimeImmutable('2026-08-01 12:00:00'), $now));
        self::assertEqualsWithDelta(exp(-3 / 30), MspRatingFormula::decay(new DateTimeImmutable('2026-04-18 12:00:00'), $now), 1e-12);
        self::assertEqualsWithDelta(exp(-21 / 30), MspRatingFormula::decay(new DateTimeImmutable('2024-10-18 12:00:00'), $now), 1e-12);
    }

    public function testRatingBlendsThePortfolios(): void
    {
        self::assertSame(0.0, MspRatingFormula::rating([], [0.9]));
        self::assertEqualsWithDelta(0.75 * 0.5 + 0.25 * 0.9, MspRatingFormula::rating([0.6, 0.4], [0.9]), 1e-12);
        self::assertEqualsWithDelta(0.75 * 0.5, MspRatingFormula::rating([0.5], []), 1e-12);
    }

    /**
     * The scan MspRatingCalculator used until 2026-10 (computePercentileInSortedArray)
     *
     * @param list<int> $sortedTimes
     */
    private static function linearPercentile(int $playerTime, array $sortedTimes): float
    {
        $total = count($sortedTimes);

        if ($total <= 1) {
            return 0.5;
        }

        $slowerCount = 0;
        $tiedCount = 0;

        foreach ($sortedTimes as $time) {
            if ($time > $playerTime) {
                $slowerCount++;
            } elseif ($time === $playerTime) {
                $tiedCount++;
            }
        }

        $tiedOthers = max(0, $tiedCount - 1);

        return ($slowerCount + $tiedOthers / 2.0) / ($total - 1);
    }
}
