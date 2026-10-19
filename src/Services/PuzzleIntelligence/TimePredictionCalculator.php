<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\BrandPiecesPrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\DifficultyPriors;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorEstimate;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * Pure time-prediction math shared by the single-puzzle GetPlayerPrediction and
 * the bulk GetPlayerPredictions read models — one implementation, no database.
 *
 * Personal prediction (the player has solved the puzzle before): last time × an
 * improvement ratio for the upcoming attempt number and the gap since the last
 * solve, blended with Holt's damped trend once there are ≥ 2 attempts, floored
 * at 70 % of the personal best. Statistical prediction (never solved): the
 * player's pace for the piece count × the puzzle's difficulty × the player's
 * drift. Both with calibrated 50 / 80 % windows (README "Step 8: Time Prediction").
 */
final readonly class TimePredictionCalculator
{
    public function __construct(
        private DifficultyPriors $priors = new DifficultyPriors([new BrandPiecesPrior()]),
    ) {
    }

    /**
     * Stored with every solving-time prediction. Bump it whenever a change here (or in the inputs:
     * baselines, difficulty, improvement ratios) changes what gets predicted, so accuracy
     * statistics never mix two models silently.
     *
     * 1 = 2026-09-30, difficulty indices divided by today's baselines; 2 = 2026-10, as-of difficulty
     * indices (the solver's pace-history row before the solve, puzzle-intelligence README "Pace history");
     * 3 = 2026-10, drift, shrunk difficulty for 1-4 indices, the 500-scaled pace for thin sizes and
     * calibrated 50 / 80 % windows (README "Step 8: Time Prediction").
     */
    public const int MODEL_VERSION = 3;

    /** From this many difficulty indices on a puzzle is rated (its median is the difficulty) */
    public const int RATED_INDICES = 5;

    private const int DRIFT_WINDOW = 10;
    private const int DRIFT_SHRINK = 5;
    private const float DRIFT_CLIP = 0.5;
    private const int SPREAD_WINDOW = 20;
    private const int SPREAD_SHRINK = 5;
    private const float SPREAD_CLIP = 0.5;

    public const float DEFAULT_IMPROVEMENT_RATIO = 0.90;

    public const int MAX_TRANSITION = 4;

    private const float HOLT_ALPHA = 0.5;
    private const float HOLT_BETA = 0.4;
    private const float HOLT_PHI = 0.8;

    /**
     * Ratio table transition ("from attempt N") the next attempt is predicted with.
     */
    public static function transitionFor(int $attemptCount): int
    {
        return min($attemptCount, self::MAX_TRANSITION);
    }

    /**
     * Whole days between now and the last solve — the input of the gap bucket.
     */
    public static function gapDays(DateTimeImmutable $now, DateTimeImmutable $lastSolvedAt): int
    {
        return (int) $now->diff($lastSolvedAt)->days;
    }

    public static function classifyGap(int $gapDays): string
    {
        if ($gapDays < 30) {
            return 'lt30d';
        }

        if ($gapDays < 90) {
            return '1_3m';
        }

        if ($gapDays < 365) {
            return '3_12m';
        }

        return 'gt12m';
    }

    /**
     * Improvement ratio for the next attempt: the player's own ratio corrected by
     * the global gap effect when both global values are known, otherwise the
     * global ratio for the gap bucket, otherwise the default.
     */
    public static function resolveRatio(null|float $playerRatio, null|float $globalRatioForGap, null|float $globalRatioAll): float
    {
        if ($playerRatio !== null) {
            if ($globalRatioForGap === null || $globalRatioAll === null || $globalRatioAll == 0.0) {
                return $playerRatio;
            }

            return $playerRatio * ($globalRatioForGap / $globalRatioAll);
        }

        return $globalRatioForGap ?? self::DEFAULT_IMPROVEMENT_RATIO;
    }

    /**
     * The prediction for a repeat: last time × an improvement ratio, blended with Holt's damped trend once there are
     * 2+ attempts, floored at 70 % of the personal best. The window comes from the player's own repeat spread (the
     * stored repeat predictions' residuals, playerSpread()), calibrated per WindowBucket.
     *
     * @param non-empty-list<int> $times chronologically ordered solve times of the player on the puzzle
     */
    public function personal(
        array $times,
        float $improvementRatio,
        null|float $repeatSpread = null,
        int $piecesCount = 500,
        null|PredictionModelParameters $parameters = null,
    ): TimePredictionResult {
        $parameters ??= PredictionModelParameters::defaults();
        $count = count($times);
        $bestTime = min($times);
        $lastTime = $times[$count - 1];

        $ratioPrediction = $lastTime * $improvementRatio;

        if ($count >= 6) {
            // Pure Holt's damped trend
            $predicted = self::holtsDampedTrend($times);
        } elseif ($count >= 2) {
            // Blend ratio-based + Holt's
            $holtsPrediction = self::holtsDampedTrend($times);
            $holtsWeight = min(1.0, ($count - 1) / 5);
            $predicted = (int) round($holtsWeight * $holtsPrediction + (1.0 - $holtsWeight) * $ratioPrediction);
        } else {
            // N=1: pure ratio prediction
            $predicted = (int) round($ratioPrediction);
        }

        // Safety floor: can't predict faster than 30% improvement over personal best
        $predicted = max($predicted, (int) round($bestTime * 0.70), 1);

        $spread = $repeatSpread ?? PredictionModelParameters::GLOBAL_SPREAD;
        $bucket = WindowBucket::repeat($spread, $parameters->repeatConsistencyEdges, PiecesGroup::fromPiecesCount($piecesCount));
        $window = self::window((float) $predicted, $parameters->repeatSigma($spread), $parameters->quantilesFor($bucket));

        return new TimePredictionResult(
            predictedSeconds: $predicted,
            rangeLowSeconds: $window[1],
            rangeHighSeconds: $window[2],
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: $count,
            predictedAttemptNumber: $count + 1,
            lastTimeSeconds: $lastTime,
            range80LowSeconds: $window[0],
            range80HighSeconds: $window[3],
            windowBucket: $bucket->key(),
        );
    }

    /**
     * The prediction for a first try: the player's pace × the puzzle's difficulty × the player's drift, with a
     * calibrated window (README "Step 8: Time Prediction"). Null when the inputs do not carry a prediction: no pace
     * for the piece count, or no difficulty - a puzzle without indices counts only with a prior and $allowPriorOnly
     * (surfaces do not show those yet, Insights plan Phase 6).
     */
    public function statistical(
        StatisticalPredictionInputs $inputs,
        null|PredictionModelParameters $parameters = null,
        bool $allowPriorOnly = false,
    ): null|TimePredictionResult {
        $parameters ??= PredictionModelParameters::defaults();
        $prior = $this->priors->priorFor($inputs->prior);
        $difficulty = self::logDifficulty($inputs->kIndices, $inputs->sumLogIndices, $inputs->difficultyScore, $prior?->logDifficulty, $parameters->tau);

        if ($difficulty === null) {
            if ($prior === null || $inputs->kIndices > 0 || $allowPriorOnly === false) {
                return null;
            }

            $difficulty = [$prior->logDifficulty, $prior->sourceWithoutSolves];
        }

        $baseline = self::baselineFor($inputs, $parameters);

        if ($baseline === null) {
            return null;
        }

        [$baselineSeconds, $drift, $spread, $paceSource] = $baseline;
        [$logDifficulty, $source] = $difficulty;

        // pace × drift first, then the difficulty: the order of operations the Picker's SQL twin uses
        // (StatisticalInputsSql::centreSql()) - a rated puzzle multiplies by its score itself
        $multiplier = $source === DifficultySource::Solves && $inputs->difficultyScore !== null ? $inputs->difficultyScore : exp($logDifficulty);
        $centre = $baselineSeconds * exp($drift) * $multiplier;
        $group = PiecesGroup::fromPiecesCount($inputs->piecesCount);
        $spread ??= PredictionModelParameters::GLOBAL_SPREAD;
        $bucket = WindowBucket::firstTry($spread, $parameters->firstTryConsistencyEdges, $inputs->kIndices, $group);
        $window = self::window($centre, $parameters->firstTrySigma($spread, $inputs->kIndices, $group), $parameters->quantilesFor($bucket));

        return new TimePredictionResult(
            predictedSeconds: max((int) round($centre), 1),
            rangeLowSeconds: $window[1],
            rangeHighSeconds: $window[2],
            difficultyForPlayer: round(exp($logDifficulty), 4),
            range80LowSeconds: $window[0],
            range80HighSeconds: $window[3],
            difficultySource: $source,
            kIndices: $inputs->kIndices,
            priorLog: $prior?->logDifficulty,
            logDifficulty: round($logDifficulty, 6),
            windowBucket: $bucket->key(),
            paceSource: $paceSource,
        );
    }

    public function prior(PriorContext $context): null|PriorEstimate
    {
        return $this->priors->priorFor($context);
    }

    /**
     * The puzzle's log difficulty: the log of the median index from 5 indices on (the shown difficulty), the shrunk
     * mean (Σ log indices + τ × prior) ÷ (k + τ) for 1-4 indices - toward the average puzzle (0.0) without a prior.
     * Null for k = 0 (the caller decides whether a prior alone may stand in).
     *
     * @return null|array{0: float, 1: DifficultySource}
     */
    public static function logDifficulty(int $k, null|float $sumLogIndices, null|float $difficultyScore, null|float $priorLog, float $tau): null|array
    {
        if ($k >= self::RATED_INDICES) {
            return $difficultyScore !== null && $difficultyScore > 0.0 ? [log($difficultyScore), DifficultySource::Solves] : null;
        }

        if ($k <= 0 || $sumLogIndices === null) {
            return null;
        }

        return [($sumLogIndices + $tau * ($priorLog ?? 0.0)) / ($k + $tau), DifficultySource::Shrunk];
    }

    /**
     * The pace a first try is predicted from, with the drift and spread that belong to it: the player's 500-piece
     * pace scaled by the group's size exponent while their own baseline at a thin size rests on fewer first attempts
     * than the fitted threshold, else their own baseline (direct: with its drift and spread; interpolated or
     * extrapolated: no drift, the global spread).
     *
     * @return null|array{0: float, 1: float, 2: null|float, 3: PaceSource} baseline seconds, drift, spread, what the pace is
     */
    public static function baselineFor(StatisticalPredictionInputs $inputs, PredictionModelParameters $parameters): null|array
    {
        $rule = $inputs->piecesCount !== 500 ? $parameters->thinSizeRule(PiecesGroup::fromPiecesCount($inputs->piecesCount)) : null;
        $ownCount = $inputs->ownBaselineDirect ? $inputs->ownQualifyingCount : 0;

        if ($rule !== null && $inputs->pace500Seconds !== null && $inputs->pace500Seconds > 0 && $ownCount < $rule['max_own_solves']) {
            return [
                $inputs->pace500Seconds * ($inputs->piecesCount / 500) ** $rule['alpha'],
                $inputs->drift500 ?? 0.0,
                $inputs->spread500,
                PaceSource::Scaled500,
            ];
        }

        if ($inputs->ownBaselineSeconds === null || $inputs->ownBaselineSeconds <= 0) {
            return null;
        }

        if ($inputs->ownBaselineDirect) {
            return [(float) $inputs->ownBaselineSeconds, $inputs->ownDrift ?? 0.0, $inputs->ownSpread, PaceSource::Own];
        }

        return [(float) $inputs->ownBaselineSeconds, 0.0, null, PaceSource::Interpolated];
    }

    /**
     * The window around a centre: centre × exp(q × sigma) for the 10 / 25 / 75 / 90 % quantiles, whole seconds, at
     * least 1 second, the inner window never wider than the outer one.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $quantiles
     * @return array{0: int, 1: int, 2: int, 3: int} 80 % low, 50 % low, 50 % high, 80 % high
     */
    public static function window(float $centre, float $sigma, array $quantiles): array
    {
        $bounds = array_map(
            static fn (float $quantile): int => max((int) round($centre * exp($quantile * $sigma)), 1),
            $quantiles,
        );

        return [
            min($bounds[0], $bounds[1]),
            $bounds[1],
            $bounds[2],
            max($bounds[2], $bounds[3]),
        ];
    }

    /**
     * Improvement drift (log) from a pair's residuals ln(actual ÷ predicted without drift), oldest first: the mean
     * of the last 10, each clipped at ±0.5 like the spread's (one typo time moves the next first tries by at most
     * ~3 %, not by ~14 %), shrunk toward 0 with few (÷ (n + 5)).
     *
     * @param list<float> $residuals
     */
    public static function drift(array $residuals): float
    {
        $sum = 0.0;
        $last = array_slice($residuals, -self::DRIFT_WINDOW);

        foreach ($last as $residual) {
            $sum += max(-self::DRIFT_CLIP, min(self::DRIFT_CLIP, $residual));
        }

        return round($sum / (count($last) + self::DRIFT_SHRINK), 4);
    }

    /**
     * A player's spread (log): the root mean square of their last 20 residuals, each clipped at ±0.5 (one wild solve
     * does not make a player inconsistent), shrunk toward the global spread with few (+ 5 pseudo-residuals).
     *
     * @param list<float> $residuals oldest first
     */
    public static function playerSpread(array $residuals): float
    {
        $sum = 0.0;
        $last = array_slice($residuals, -self::SPREAD_WINDOW);

        foreach ($last as $residual) {
            $sum += min(abs($residual), self::SPREAD_CLIP) ** 2;
        }

        $global = PredictionModelParameters::GLOBAL_SPREAD ** 2;

        return round(sqrt(($sum + self::SPREAD_SHRINK * $global) / (count($last) + self::SPREAD_SHRINK)), 4);
    }

    /**
     * Holt's damped trend exponential smoothing — returns predicted next value.
     *
     * @param non-empty-list<int> $times chronologically ordered solve times
     */
    private static function holtsDampedTrend(array $times): int
    {
        $count = count($times);

        $level = (float) $times[0];
        $trend = (float) ($times[1] - $times[0]);

        for ($i = 1; $i < $count; $i++) {
            $prevLevel = $level;
            $level = self::HOLT_ALPHA * $times[$i] + (1.0 - self::HOLT_ALPHA) * ($level + self::HOLT_PHI * $trend);
            $trend = self::HOLT_BETA * ($level - $prevLevel) + (1.0 - self::HOLT_BETA) * self::HOLT_PHI * $trend;
        }

        return (int) round($level + self::HOLT_PHI * $trend);
    }
}
