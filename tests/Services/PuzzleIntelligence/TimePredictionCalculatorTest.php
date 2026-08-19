<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * Pure math of the time predictions - the DB-backed parity is pinned by
 * GetPlayerPredictionsTest, this pins the formulas themselves.
 */
final class TimePredictionCalculatorTest extends TestCase
{
    private TimePredictionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TimePredictionCalculator();
    }

    #[DataProvider('provideGapDays')]
    public function testClassifyGap(int $days, string $bucket): void
    {
        self::assertSame($bucket, TimePredictionCalculator::classifyGap($days));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideGapDays(): iterable
    {
        yield 'today' => [0, 'lt30d'];
        yield '29 days' => [29, 'lt30d'];
        yield '30 days' => [30, '1_3m'];
        yield '89 days' => [89, '1_3m'];
        yield '90 days' => [90, '3_12m'];
        yield '364 days' => [364, '3_12m'];
        yield 'a year' => [365, 'gt12m'];
        yield 'years' => [900, 'gt12m'];
    }

    public function testGapDaysAndTransition(): void
    {
        $now = new DateTimeImmutable('2026-08-19 10:00:00');

        self::assertSame(0, TimePredictionCalculator::gapDays($now, new DateTimeImmutable('2026-08-19 08:00:00')));
        self::assertSame(10, TimePredictionCalculator::gapDays($now, new DateTimeImmutable('2026-08-09 09:00:00')));
        self::assertSame(45, TimePredictionCalculator::gapDays($now, new DateTimeImmutable('2026-07-05 10:00:00')));

        self::assertSame(1, TimePredictionCalculator::transitionFor(1));
        self::assertSame(3, TimePredictionCalculator::transitionFor(3));
        self::assertSame(4, TimePredictionCalculator::transitionFor(4));
        self::assertSame(4, TimePredictionCalculator::transitionFor(12), 'Transitions are capped');
    }

    public function testResolveRatio(): void
    {
        // Player ratio corrected by the global gap effect
        self::assertEqualsWithDelta(0.95 * (1.10 / 1.00), TimePredictionCalculator::resolveRatio(0.95, 1.10, 1.00), 0.000001);
        // Player ratio alone when the global values are incomplete or degenerate
        self::assertSame(0.95, TimePredictionCalculator::resolveRatio(0.95, null, 1.00));
        self::assertSame(0.95, TimePredictionCalculator::resolveRatio(0.95, 1.10, null));
        self::assertSame(0.95, TimePredictionCalculator::resolveRatio(0.95, 1.10, 0.0));
        // No player ratio: the global ratio for the gap bucket, else the default
        self::assertSame(1.10, TimePredictionCalculator::resolveRatio(null, 1.10, 1.00));
        self::assertSame(1.10, TimePredictionCalculator::resolveRatio(null, 1.10, null));
        self::assertSame(TimePredictionCalculator::DEFAULT_IMPROVEMENT_RATIO, TimePredictionCalculator::resolveRatio(null, null, 1.00));
    }

    public function testSingleAttemptIsAPureRatioPredictionWithAWideRange(): void
    {
        $result = $this->calculator->personal([2000], 0.9);

        self::assertTrue($result->isPersonalized);
        self::assertSame(1800, $result->predictedSeconds);
        self::assertSame(1, $result->personalSolveCount);
        self::assertSame(2, $result->predictedAttemptNumber);
        self::assertSame(2000, $result->lastTimeSeconds);
        self::assertSame(0.0, $result->difficultyForPlayer);
        // Spread = max(15 %, 2 min) = 270 s
        self::assertSame(1530, $result->rangeLowSeconds);
        self::assertSame(2070, $result->rangeHighSeconds);

        // Short times: the 2-minute minimum spread and the 70 % floor on the low end
        $short = $this->calculator->personal([300], 0.9);
        self::assertSame(270, $short->predictedSeconds);
        self::assertSame(210, $short->rangeLowSeconds, 'Never below 70 % of the best time');
        self::assertSame(390, $short->rangeHighSeconds);
    }

    public function testImprovingPlayerIsBlendedWithHoltsTrendAndFlooredAtSeventyPercentOfTheBest(): void
    {
        // 2200 -> 1900 -> 1700 with the default ratio: ratio part 1530, Holt part 1548, weight 0.4
        $result = $this->calculator->personal([2200, 1900, 1700], 0.9);

        self::assertSame(1537, $result->predictedSeconds);
        self::assertSame(3, $result->personalSolveCount);
        self::assertSame(4, $result->predictedAttemptNumber);
        self::assertSame(1700, $result->lastTimeSeconds);
        self::assertGreaterThanOrEqual($result->rangeLowSeconds, $result->predictedSeconds);
        self::assertLessThanOrEqual($result->rangeHighSeconds, $result->predictedSeconds);
        self::assertGreaterThanOrEqual(1190, $result->rangeLowSeconds);

        // A ridiculous ratio cannot push the prediction under 70 % of the personal best
        $floored = $this->calculator->personal([1000, 1000], 0.1);
        self::assertSame(700, $floored->predictedSeconds);
        self::assertSame(700, $floored->rangeLowSeconds);

        // Six or more attempts: pure Holt's damped trend, no ratio involved
        $times = [3000, 2900, 2800, 2750, 2700, 2650];
        self::assertEquals($this->calculator->personal($times, 0.5), $this->calculator->personal($times, 1.5));
    }

    public function testStatisticalPredictionUsesBaselineTimesDifficultyWithTheIqrRange(): void
    {
        $result = $this->calculator->statistical(2000, 1.1, 0.95, 1.30);

        self::assertFalse($result->isPersonalized);
        self::assertSame(2200, $result->predictedSeconds);
        self::assertSame(1900, $result->rangeLowSeconds);
        self::assertSame(2600, $result->rangeHighSeconds);
        self::assertSame(1.1, $result->difficultyForPlayer);
        self::assertNull($result->personalSolveCount);
        self::assertNull($result->lastTimeSeconds);

        // Missing indices fall back to ±15 % of the difficulty
        $fallback = $this->calculator->statistical(2000, 1.0, null, null);
        self::assertSame(2000, $fallback->predictedSeconds);
        self::assertSame(1700, $fallback->rangeLowSeconds);
        self::assertSame(2300, $fallback->rangeHighSeconds);

        // Safety bounds: 30 %..300 % of the prediction
        $wild = $this->calculator->statistical(1000, 1.0, 0.01, 9.0);
        self::assertSame(300, $wild->rangeLowSeconds);
        self::assertSame(3000, $wild->rangeHighSeconds);
    }
}
