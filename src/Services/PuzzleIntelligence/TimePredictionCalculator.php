<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\TimePredictionResult;

/**
 * Pure time-prediction math shared by the single-puzzle GetPlayerPrediction and
 * the bulk GetPlayerPredictions read models — one implementation, no database.
 *
 * Personal prediction (the player has solved the puzzle before): last time × an
 * improvement ratio for the upcoming attempt number and the gap since the last
 * solve, blended with Holt's damped trend once there are ≥ 2 attempts, floored
 * at 70 % of the personal best. Statistical prediction (never solved): the
 * player's baseline for the piece count × the puzzle's difficulty score with the
 * puzzle's p25/p75 difficulty indices as the range.
 */
final readonly class TimePredictionCalculator
{
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
     * @param non-empty-list<int> $times chronologically ordered solve times of the player on the puzzle
     */
    public function personal(array $times, float $improvementRatio): TimePredictionResult
    {
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
        $predicted = max($predicted, (int) round($bestTime * 0.70));

        // Range calculation
        if ($count >= 2) {
            // Use residual-based range from Holt's fitted values
            $fittedValues = self::holtsFittedValues($times);
            $residuals = [];

            for ($i = 0; $i < $count; $i++) {
                $residuals[] = abs($times[$i] - $fittedValues[$i]);
            }

            $mad = array_sum($residuals) / count($residuals);
            $spread = max($mad * 1.5, $predicted * 0.05);
        } else {
            // N=1: wider spread (15% or 2 minutes minimum)
            $spread = max($predicted * 0.15, 120);
        }

        $rangeLow = (int) round($predicted - $spread);
        $rangeHigh = (int) round($predicted + $spread);

        // Safety: range low can't be below 70% of best time
        $rangeLow = max($rangeLow, (int) round($bestTime * 0.70));
        $rangeLow = max($rangeLow, 1);

        return new TimePredictionResult(
            predictedSeconds: $predicted,
            rangeLowSeconds: $rangeLow,
            rangeHighSeconds: $rangeHigh,
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: $count,
            predictedAttemptNumber: $count + 1,
            lastTimeSeconds: $lastTime,
        );
    }

    public function statistical(int $baselineSeconds, float $difficultyScore, null|float $p25, null|float $p75): TimePredictionResult
    {
        $predictedSeconds = (int) round($baselineSeconds * $difficultyScore);

        // Use pre-computed IQR, with fallback for pre-migration state
        $p25 ??= $difficultyScore * 0.85;
        $p75 ??= $difficultyScore * 1.15;

        $rangeLow = (int) round($baselineSeconds * $p25);
        $rangeHigh = (int) round($baselineSeconds * $p75);

        // Safety bounds
        $rangeLow = (int) max($rangeLow, (int) round($predictedSeconds * 0.30), 1);
        $rangeHigh = (int) min($rangeHigh, (int) round($predictedSeconds * 3.00));

        return new TimePredictionResult(
            predictedSeconds: $predictedSeconds,
            rangeLowSeconds: $rangeLow,
            rangeHighSeconds: $rangeHigh,
            difficultyForPlayer: $difficultyScore,
        );
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

    /**
     * Returns fitted values from Holt's damped trend for residual calculation.
     *
     * @param non-empty-list<int> $times
     * @return list<float>
     */
    private static function holtsFittedValues(array $times): array
    {
        $count = count($times);

        $level = (float) $times[0];
        $trend = (float) ($times[1] - $times[0]);

        $fittedValues = [(float) $times[0]];

        for ($i = 1; $i < $count; $i++) {
            $fittedValues[] = $level + self::HOLT_PHI * $trend;
            $prevLevel = $level;
            $level = self::HOLT_ALPHA * $times[$i] + (1.0 - self::HOLT_ALPHA) * ($level + self::HOLT_PHI * $trend);
            $trend = self::HOLT_BETA * ($level - $prevLevel) + (1.0 - self::HOLT_BETA) * self::HOLT_PHI * $trend;
        }

        return $fittedValues;
    }
}
