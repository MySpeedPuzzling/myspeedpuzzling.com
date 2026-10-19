<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\BrandPiecesPrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

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

    public function testSingleAttemptIsAPureRatioPredictionWithACalibratedWindow(): void
    {
        $result = $this->calculator->personal([2000], 0.9);

        self::assertTrue($result->isPersonalized);
        self::assertSame(1800, $result->predictedSeconds);
        self::assertSame(1, $result->personalSolveCount);
        self::assertSame(2, $result->predictedAttemptNumber);
        self::assertSame(2000, $result->lastTimeSeconds);
        self::assertSame(0.0, $result->difficultyForPlayer);

        // No repeat history: the global spread, the default repeat model, the 'r' quantiles
        $parameters = PredictionModelParameters::defaults();
        $sigma = $parameters->repeatSigma(PredictionModelParameters::GLOBAL_SPREAD);
        self::assertSame([
            (int) round(1800 * exp(-1.17 * $sigma)),
            (int) round(1800 * exp(-0.62 * $sigma)),
            (int) round(1800 * exp(0.58 * $sigma)),
            (int) round(1800 * exp(1.21 * $sigma)),
        ], [$result->range80LowSeconds, $result->rangeLowSeconds, $result->rangeHighSeconds, $result->range80HighSeconds]);
        self::assertSame('r|c1|pieces_500', $result->windowBucket);
    }

    public function testARepeatWindowNarrowsForAConsistentPlayer(): void
    {
        $consistent = $this->calculator->personal([2000], 0.9, TimePredictionCalculator::playerSpread(array_fill(0, 20, 0.02)));
        $erratic = $this->calculator->personal([2000], 0.9, TimePredictionCalculator::playerSpread(array_fill(0, 20, 0.30)));

        self::assertLessThan($erratic->rangeHighSeconds - $erratic->rangeLowSeconds, $consistent->rangeHighSeconds - $consistent->rangeLowSeconds);
        self::assertSame(1800, $consistent->predictedSeconds);
        self::assertSame(1800, $erratic->predictedSeconds);
        self::assertStringStartsWith('r|c0|', (string) $consistent->windowBucket);
        self::assertStringStartsWith('r|c3|', (string) $erratic->windowBucket);
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

        // A ridiculous ratio cannot push the prediction under 70 % of the personal best
        $floored = $this->calculator->personal([1000, 1000], 0.1);
        self::assertSame(700, $floored->predictedSeconds);

        // Six or more attempts: pure Holt's damped trend, no ratio involved
        $times = [3000, 2900, 2800, 2750, 2700, 2650];
        self::assertEquals($this->calculator->personal($times, 0.5), $this->calculator->personal($times, 1.5));
    }

    public function testRatedPuzzleIsPaceTimesTheMedianDifficultyTimesTheDrift(): void
    {
        $result = $this->calculator->statistical(self::inputs(k: 12, sumLog: 1.0, score: 1.1));
        self::assertNotNull($result);
        self::assertFalse($result->isPersonalized);
        self::assertSame(2200, $result->predictedSeconds);
        self::assertSame(1.1, $result->difficultyForPlayer);
        self::assertSame(DifficultySource::Solves, $result->difficultySource);
        self::assertSame(12, $result->kIndices);
        self::assertNull($result->personalSolveCount);
        self::assertSame('f|c1|k5|pieces_500', $result->windowBucket);

        // Asymmetric, nested windows around the centre
        self::assertNotNull($result->range80LowSeconds);
        self::assertNotNull($result->range80HighSeconds);
        self::assertLessThan($result->rangeLowSeconds, $result->range80LowSeconds);
        self::assertLessThan($result->predictedSeconds, $result->rangeLowSeconds);
        self::assertGreaterThan($result->predictedSeconds, $result->rangeHighSeconds);
        self::assertGreaterThan($result->rangeHighSeconds, $result->range80HighSeconds);

        // The player's recent first tries ran 5 % faster than their pace says
        $drifting = $this->calculator->statistical(self::inputs(k: 12, sumLog: 1.0, score: 1.1, ownDrift: -0.05));
        self::assertNotNull($drifting);
        self::assertSame((int) round(2000 * 1.1 * exp(-0.05)), $drifting->predictedSeconds);
    }

    public function testFewIndicesAreShrunkTowardThePrior(): void
    {
        $tau = PredictionModelParameters::defaults()->tau;
        $log = log(1.2);

        // No prior: toward the average puzzle
        $alone = $this->calculator->statistical(self::inputs(k: 2, sumLog: 2 * $log));
        self::assertNotNull($alone);
        self::assertSame(DifficultySource::Shrunk, $alone->difficultySource);
        self::assertEqualsWithDelta(2 * $log / (2 + $tau), (float) $alone->logDifficulty, 0.000001);
        self::assertSame((int) round(2000 * exp(2 * $log / (2 + $tau))), $alone->predictedSeconds);

        // A brand prior of +0.2 (its group without the puzzle's own share): pulled toward it
        $prior = new PriorContext('puzzle', 500, 'brand', brandPiecesGroupSumLog: 0.2 * 80 + 0.5, brandPiecesGroupCount: 62, brandPiecesOwnSumLog: 0.5, brandPiecesOwnCount: 2);
        $withPrior = $this->calculator->statistical(self::inputs(k: 2, sumLog: 2 * $log, prior: $prior));
        self::assertNotNull($withPrior);
        self::assertEqualsWithDelta(0.2 * 80 / 80, (float) $withPrior->priorLog, 0.000001);
        self::assertEqualsWithDelta((2 * $log + $tau * 0.2) / (2 + $tau), (float) $withPrior->logDifficulty, 0.000001);

        // Wider than a rated puzzle's window
        $rated = $this->calculator->statistical(self::inputs(k: 25, sumLog: 0.0, score: 1.0));
        self::assertNotNull($rated);
        self::assertGreaterThan(
            ($rated->rangeHighSeconds - $rated->rangeLowSeconds) / $rated->predictedSeconds,
            ($alone->rangeHighSeconds - $alone->rangeLowSeconds) / $alone->predictedSeconds,
        );
    }

    public function testAPuzzleWithoutIndicesRestsOnItsPriorOnlyWhenAsked(): void
    {
        $prior = new PriorContext('puzzle', 500, 'brand', brandPiecesGroupSumLog: 3.0, brandPiecesGroupCount: 40);

        self::assertNull($this->calculator->statistical(self::inputs(k: 0, sumLog: 0.0, prior: $prior)));

        $priorOnly = $this->calculator->statistical(self::inputs(k: 0, sumLog: 0.0, prior: $prior), allowPriorOnly: true);
        self::assertNotNull($priorOnly);
        self::assertSame(0, $priorOnly->kIndices);
        self::assertEqualsWithDelta(3.0 / 60, (float) $priorOnly->logDifficulty, 0.000001);
        self::assertSame(DifficultySource::Shrunk, $priorOnly->difficultySource);

        // ... and only with a prior: a group below the minimum has none
        $tooSmall = new PriorContext('puzzle', 500, 'brand', brandPiecesGroupSumLog: 3.0, brandPiecesGroupCount: BrandPiecesPrior::MINIMUM_GROUP_INDICES - 1);
        self::assertNull($this->calculator->statistical(self::inputs(k: 0, sumLog: 0.0, prior: $tooSmall), allowPriorOnly: true));
    }

    public function testAThinPieceCountUsesTheScaled500PieceWhereTheFitSaysSo(): void
    {
        $parameters = PredictionModelParameters::defaults()->with(thinSizes: [PiecesGroup::Thousand->value => ['alpha' => 1.4, 'max_own_solves' => 20]]);

        // Seven own 1000-piece first attempts (< 20): the 500-piece pace × 2^1.4 with its drift
        $thin = $this->calculator->statistical(self::inputs(k: 12, sumLog: 0.0, score: 1.0, pieces: 1000, ownBaseline: 9000, ownCount: 7, pace500: 3000, drift500: -0.02), $parameters);
        self::assertNotNull($thin);
        self::assertSame((int) round(3000 * 2 ** 1.4 * exp(-0.02)), $thin->predictedSeconds);

        // Enough own first attempts: their own pace
        $own = $this->calculator->statistical(self::inputs(k: 12, sumLog: 0.0, score: 1.0, pieces: 1000, ownBaseline: 9000, ownCount: 25, pace500: 3000), $parameters);
        self::assertNotNull($own);
        self::assertSame(9000, $own->predictedSeconds);

        // No own baseline at all: still predicted from the 500 pace
        $none = $this->calculator->statistical(self::inputs(k: 12, sumLog: 0.0, score: 1.0, pieces: 1000, ownBaseline: null, ownCount: 0, pace500: 3000), $parameters);
        self::assertNotNull($none);

        // Without the rule (the default model): nothing without an own baseline
        self::assertNull($this->calculator->statistical(self::inputs(k: 12, sumLog: 0.0, score: 1.0, pieces: 1000, ownBaseline: null, ownCount: 0, pace500: 3000)));
    }

    public function testDriftAndSpreadAreShrunkWithFewResiduals(): void
    {
        self::assertSame(0.0, TimePredictionCalculator::drift([]));
        self::assertSame(round(-0.1 / 6, 4), TimePredictionCalculator::drift([-0.1]));
        // Only the last 10 count
        self::assertSame(round(10 * -0.1 / 15, 4), TimePredictionCalculator::drift([...array_fill(0, 30, 0.5), ...array_fill(0, 10, -0.1)]));

        self::assertSame(PredictionModelParameters::GLOBAL_SPREAD, TimePredictionCalculator::playerSpread([]));
        // A wild solve counts as 0.5 at most
        self::assertSame(TimePredictionCalculator::playerSpread([0.5]), TimePredictionCalculator::playerSpread([3.0]));
        self::assertSame(round(sqrt((20 * 0.02 ** 2 + 5 * 0.13 ** 2) / 25), 4), TimePredictionCalculator::playerSpread(array_fill(0, 30, 0.02)));
    }

    public function testTheWindowNeverTurnsInsideOutAndStaysPositive(): void
    {
        self::assertSame([1, 1, 1, 2], TimePredictionCalculator::window(1.4, 0.1, [-1.0, -0.5, 0.5, 1.0]));
        self::assertSame([95, 95, 105, 105], TimePredictionCalculator::window(100.0, 0.1, [-0.2, -0.5, 0.5, 0.2]));
    }

    private static function inputs(
        int $k,
        float $sumLog,
        null|float $score = null,
        int $pieces = 500,
        null|int $ownBaseline = 2000,
        int $ownCount = 30,
        null|float $ownDrift = null,
        null|int $pace500 = null,
        null|float $drift500 = null,
        null|PriorContext $prior = null,
    ): StatisticalPredictionInputs {
        return new StatisticalPredictionInputs(
            piecesCount: $pieces,
            ownBaselineSeconds: $ownBaseline,
            ownBaselineDirect: $ownCount >= 5,
            ownQualifyingCount: $ownCount,
            ownDrift: $ownDrift,
            ownSpread: null,
            pace500Seconds: $pace500,
            drift500: $drift500,
            spread500: null,
            kIndices: $k,
            sumLogIndices: $sumLog,
            difficultyScore: $score,
            prior: $prior ?? new PriorContext('puzzle', $pieces),
        );
    }
}
