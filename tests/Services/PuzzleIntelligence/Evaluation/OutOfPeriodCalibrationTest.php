<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\OutOfPeriodCalibration;
use SpeedPuzzling\Web\Value\WindowRefit;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;
use SpeedPuzzling\Web\Value\StoredPredictionWindows;

/**
 * Honesty out of period (OutOfPeriodCalibration) on synthetic solves: windows calibrated before the split hold the
 * later solves when nothing drifts and fail when the later solves spread more; scales are refitted per 30-day block;
 * the verdict judges the Wilson interval, with hysteresis for a group open before.
 */
final class OutOfPeriodCalibrationTest extends TestCase
{
    private const int DAY = 86400;

    /** Ten log offsets of the actual time from the centre: 5 inside ± 0.1 (the 50 % window), 8 inside ± 0.2 (the 80 %) */
    private const array OFFSETS = [-0.02, 0.02, -0.05, 0.05, 0.08, 0.12, -0.15, 0.18, 0.3, -0.4];

    public function testWindowsThatStayCalibratedPassOutOfPeriod(): void
    {
        $calibration = new OutOfPeriodCalibration();
        $this->addSolves($calibration, fromDay: 0, days: 180, perDay: 10, spread: 1.0);

        $metrics = $calibration->metrics(90.0 * self::DAY);

        self::assertSame(900, $metrics['fitRows']);
        self::assertSame(900, $metrics['testRows']);
        self::assertSame(3, $metrics['blocks'], '90 days after the split = three 30-day blocks');
        self::assertSame(0.5, $metrics['hit50']);
        self::assertSame(0.8, $metrics['hit80']);
        self::assertSame(0.5, $metrics['inSample50']);
        self::assertNotNull($metrics['hit50Low']);
        self::assertGreaterThanOrEqual(0.47, $metrics['hit50Low']);
        self::assertSame([], OutOfPeriodCalibration::failures($metrics, previouslyOpen: false));
    }

    /**
     * The later solves spread 40 % more: the scale fitted before the split is too narrow for them - the stored
     * windows (fitted on the whole period) would still look fine in-sample.
     */
    public function testWindowsTooNarrowForTheLaterSolvesFail(): void
    {
        $calibration = new OutOfPeriodCalibration();
        $this->addSolves($calibration, fromDay: 0, days: 90, perDay: 10, spread: 1.0);
        $this->addSolves($calibration, fromDay: 90, days: 90, perDay: 10, spread: 1.4);

        $metrics = $calibration->metrics(90.0 * self::DAY);

        // The first block holds 40 %; the later blocks' fits learn the wider spread - pooled still well below 47 %
        self::assertNotNull($metrics['hit50']);
        self::assertLessThan(0.47, $metrics['hit50']);
        $failures = OutOfPeriodCalibration::failures($metrics, previouslyOpen: false);
        self::assertNotSame([], $failures);
        self::assertStringContainsString('50 % window', $failures[0]);
    }

    /**
     * Each 30-day block is fitted on everything before it: a drift that began after the split is learned by the later
     * blocks' fits (the production fit is refitted daily).
     */
    public function testEachBlockIsFittedOnEverythingBeforeIt(): void
    {
        $calibration = new OutOfPeriodCalibration();
        $this->addSolves($calibration, fromDay: 0, days: 90, perDay: 10, spread: 1.0);
        $this->addSolves($calibration, fromDay: 90, days: 300, perDay: 10, spread: 1.4);

        $metrics = $calibration->metrics(90.0 * self::DAY);

        self::assertSame(10, $metrics['blocks']);
        // The 50 % scale of the calibrated solves is 0.8 (their 5th offset 0.08 of the half-width 0.1); the last
        // block's fit has seen 270 days of the 1.4 × wider solves: 0.8 × 1.4 = 1.12
        self::assertNotNull($metrics['scale50']);
        self::assertGreaterThan(1.05, $metrics['scale50']);
    }

    /**
     * The picture-only window's refit (WindowRefit::imageWindow()) weighs recent solves more, like step 6: after a
     * drift it is closer to honest on the later blocks than the equal-weight refit, which still remembers the old
     * spread - and the record says which refit judged it.
     */
    public function testTheImageRefitWeighsRecentSolvesLikeItsProductionFit(): void
    {
        $equal = new OutOfPeriodCalibration();
        $recent = new OutOfPeriodCalibration(WindowRefit::imageWindow());

        foreach ([$equal, $recent] as $calibration) {
            $sequence = 0;

            for ($day = 0; $day < 300; $day++) {
                for ($i = 0; $i < 20; $i++) {
                    $calibration->add(($day + 0.5) * self::DAY, self::actual($sequence++, $day < 150 ? 1.0 : 1.4), self::windows(), 0);
                }
            }
        }

        $equalMetrics = $equal->metrics(150.0 * self::DAY);
        $recentMetrics = $recent->metrics(150.0 * self::DAY);

        self::assertNotNull($equalMetrics['hit50']);
        self::assertNotNull($recentMetrics['hit50']);
        self::assertLessThan(abs($equalMetrics['hit50'] - 0.5), abs($recentMetrics['hit50'] - 0.5));
        self::assertArrayNotHasKey('refitHalfLifeDays', $equalMetrics);
        self::assertSame(PredictionModelFitter::IMAGE_WINDOW_HALF_LIFE_DAYS, $recentMetrics['refitHalfLifeDays'] ?? null);
        self::assertTrue($recentMetrics['refitByFirstSolver'] ?? null);
        self::assertSame(PredictionModelFitter::IMAGE_WINDOW_MINIMUM_EFFECTIVE, $recentMetrics['refitMinimumEffective'] ?? null);
    }

    /**
     * First solvers (k = 0) scatter less than later ones and their share falls over time: one pooled scale refitted on
     * the earlier mix is off for the later one, the image refit's own scales per k bucket are not.
     */
    public function testTheImageRefitGivesFirstAndLaterSolversTheirOwnScales(): void
    {
        $pooled = new OutOfPeriodCalibration(WindowRefit::imageWindow());
        $keyed = new OutOfPeriodCalibration(WindowRefit::imageWindow());
        $windows = self::windows();
        $sequence = [0, 0];

        for ($day = 0; $day < 300; $day++) {
            // 80 % first solvers at the start, 20 % at the end
            $firstShare = 0.8 - 0.6 * $day / 300;

            for ($i = 0; $i < 40; $i++) {
                $first = ($i + 0.5) / 40 < $firstShare;
                $actual = self::actual($sequence[$first ? 0 : 1]++, $first ? 0.8 : 1.4);
                $pooled->add(($day + 0.5) * self::DAY, $actual, $windows);
                $keyed->add(($day + 0.5) * self::DAY, $actual, $windows, $first ? 0 : 3);
            }
        }

        $pooledMetrics = $pooled->metrics(150.0 * self::DAY);
        $keyedMetrics = $keyed->metrics(150.0 * self::DAY);

        self::assertNotNull($pooledMetrics['hit80']);
        self::assertNotNull($keyedMetrics['hit80']);
        self::assertLessThan(abs($pooledMetrics['hit80'] - 0.8), abs($keyedMetrics['hit80'] - 0.8));
        self::assertEqualsWithDelta(0.8, $keyedMetrics['hit80'], 0.02);
    }

    public function testHysteresis(): void
    {
        $numbers = ['hit50' => 0.535, 'hit50Low' => 0.52, 'hit50High' => 0.55, 'hit80' => 0.80, 'hit80Low' => 0.79, 'hit80High' => 0.81];

        self::assertCount(1, OutOfPeriodCalibration::failures($numbers, previouslyOpen: false), 'Opening needs the Wilson interval within ± 3');
        self::assertSame([], OutOfPeriodCalibration::failures($numbers, previouslyOpen: true), 'An open group stays open within ± 5');
        self::assertCount(1, OutOfPeriodCalibration::failures(['hit50' => 0.551] + $numbers, previouslyOpen: true), 'and closes beyond');
        self::assertCount(2, OutOfPeriodCalibration::failures([], previouslyOpen: true), 'No numbers - closed');
    }

    public function testTheSplitMomentIsTheMedianSolve(): void
    {
        self::assertSame(3.0, OutOfPeriodCalibration::medianMoment([5.0, 1.0, 3.0, 2.0, 4.0]));
        self::assertNull(OutOfPeriodCalibration::medianMoment([]));
    }

    /**
     * A continuous scatter: the n-th point of the golden-ratio sequence through a logistic quantile, × $spread
     */
    private static function actual(int $n, float $spread): int
    {
        $u = fmod(($n + 0.5) * 0.6180339887498949, 1.0);

        return (int) round(1000 * exp($spread * 0.1 * log($u / (1 - $u))));
    }

    private static function windows(): StoredPredictionWindows
    {
        return new StoredPredictionWindows(
            1000,
            (int) round(1000 * exp(-0.2)),
            (int) round(1000 * exp(-0.1)),
            (int) round(1000 * exp(0.1)),
            (int) round(1000 * exp(0.2)),
        );
    }

    private function addSolves(OutOfPeriodCalibration $calibration, int $fromDay, int $days, int $perDay, float $spread): void
    {
        $windows = self::windows();

        for ($day = $fromDay; $day < $fromDay + $days; $day++) {
            for ($i = 0; $i < $perDay; $i++) {
                $offset = self::OFFSETS[$i % count(self::OFFSETS)] * $spread;
                $calibration->add(($day + 0.5) * self::DAY, (int) round(1000 * exp($offset)), $windows);
            }
        }
    }
}
