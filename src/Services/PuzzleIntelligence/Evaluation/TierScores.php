<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

use SpeedPuzzling\Web\Value\DifficultyTier;

/**
 * Tier numbers of a difficulty estimate against measured puzzles - reported next to the gate, never gating it
 * (docs/features/insights/image-estimate-gates.md, "Tiers"):
 * - the exact tier's standard error and its **label-noise ceiling**: a measured difficulty is itself an estimate (the
 *   median of n noisy indices), so even a perfect estimator lands in another tier than the label now and then. The
 *   ceiling is the share a perfect estimator would get right, with the label's noise LABEL_NOISE_VARIANCE ÷ n (plugged
 *   in at the measured value);
 * - the **tier range**: the tiers the estimate's 80 % interval spans - how often the measured tier lies inside (≈ 80 %
 *   for an honest interval, a little more: whole tiers widen it);
 * - the **ranked probability skill score** of the estimate's tier probabilities (split normal from its 10 / 90 %
 *   quantiles) against the group's own tier mix (climatology - in-sample, so a fair reference): 0 = no better than
 *   knowing the mix, 1 = perfect.
 */
final class TierScores
{
    /**
     * The noise variance of one difficulty index in a label built from n of them, as the label's mean squared
     * residual against out-of-fold estimates grows with 1 ÷ n (insights review 2026-10-26, image model F5:
     * E[r²] = 0.0107 + 0.054 ÷ n)
     */
    public const float LABEL_NOISE_VARIANCE = 0.054;

    private const float Z90 = 1.2815515655446004;
    private const int DECIMALS = 4;

    /** @var list<float> the tier boundaries in log difficulty (DifficultyTier::fromScore()) */
    private static null|array $boundaries = null;

    private int $puzzles = 0;
    private int $exact = 0;
    private float $ceiling = 0.0;
    private int $rangePuzzles = 0;
    private int $rangeHits = 0;
    private int $rangeTiers = 0;

    /** @var list<array{0: list<float>, 1: int}> per puzzle with an interval: cumulative tier probabilities, measured tier */
    private array $forecasts = [];

    /** @var array<int, int> measured tiers of the puzzles with an interval */
    private array $tierCounts = [];

    /**
     * @param null|float $p10Offset the estimate's 10 % quantile − the estimate (log), null without an interval
     * @param null|float $p90Offset the estimate's 90 % quantile − the estimate (log)
     */
    public function add(float $estimate, float $measured, int $measuredIndices, null|float $p10Offset, null|float $p90Offset): void
    {
        $measuredTier = self::tierOf($measured);

        $this->puzzles++;
        $this->exact += self::tierOf($estimate) === $measuredTier ? 1 : 0;
        $this->ceiling += self::sameTierProbability($measured, sqrt(self::LABEL_NOISE_VARIANCE / max(1, $measuredIndices)));

        if ($p10Offset === null || $p90Offset === null) {
            return;
        }

        $low = self::tierOf($estimate + min(0.0, $p10Offset));
        $high = self::tierOf($estimate + max(0.0, $p90Offset));
        $this->rangePuzzles++;
        $this->rangeHits += $measuredTier >= $low && $measuredTier <= $high ? 1 : 0;
        $this->rangeTiers += $high - $low + 1;

        $sigmaLow = max(1e-6, -$p10Offset / self::Z90);
        $sigmaHigh = max(1e-6, $p90Offset / self::Z90);
        $cumulative = [];

        foreach (self::boundaries() as $boundary) {
            $sigma = $boundary < $estimate ? $sigmaLow : $sigmaHigh;
            $cumulative[] = self::normalCdf(($boundary - $estimate) / $sigma);
        }

        $this->forecasts[] = [$cumulative, $measuredTier];
        $this->tierCounts[$measuredTier] = ($this->tierCounts[$measuredTier] ?? 0) + 1;
    }

    /**
     * @return array{tierExactSe: null|float, tierExactCeiling: null|float, tierExactOfCeiling: null|float, tierRangePuzzles: int, tierRangeCoverage: null|float, tierRangeCoverageSe: null|float, tierRangeMeanTiers: null|float, rpss: null|float}
     */
    public function metrics(): array
    {
        $n = $this->puzzles;
        $exact = $n > 0 ? $this->exact / $n : null;
        $ceiling = $n > 0 ? $this->ceiling / $n : null;
        $rangeN = $this->rangePuzzles;
        $coverage = $rangeN > 0 ? $this->rangeHits / $rangeN : null;

        return [
            'tierExactSe' => $exact !== null ? round(sqrt($exact * (1 - $exact) / $n), self::DECIMALS) : null,
            'tierExactCeiling' => $ceiling !== null ? round($ceiling, self::DECIMALS) : null,
            'tierExactOfCeiling' => $exact !== null && $ceiling > 0.0 ? round($exact / $ceiling, self::DECIMALS) : null,
            'tierRangePuzzles' => $rangeN,
            'tierRangeCoverage' => $coverage !== null ? round($coverage, self::DECIMALS) : null,
            'tierRangeCoverageSe' => $coverage !== null ? round(sqrt($coverage * (1 - $coverage) / $rangeN), self::DECIMALS) : null,
            'tierRangeMeanTiers' => $rangeN > 0 ? round($this->rangeTiers / $rangeN, self::DECIMALS) : null,
            'rpss' => $this->rpss(),
        ];
    }

    /**
     * The probability that a label with noise sigma around $measured falls in the same tier as $measured
     */
    public static function sameTierProbability(float $measured, float $sigma): float
    {
        $tier = self::tierOf($measured);
        $boundaries = self::boundaries();
        $lower = $tier > 1 ? $boundaries[$tier - 2] : -INF;
        $upper = $tier < 6 ? $boundaries[$tier - 1] : INF;

        $below = is_infinite($lower) ? 0.0 : self::normalCdf(($lower - $measured) / $sigma);
        $above = is_infinite($upper) ? 1.0 : self::normalCdf(($upper - $measured) / $sigma);

        return $above - $below;
    }

    /**
     * The standard normal CDF (Abramowitz & Stegun 7.1.26, |error| < 1.5e-7)
     */
    public static function normalCdf(float $z): float
    {
        $x = abs($z) / M_SQRT2;
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $erf = 1.0 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $z >= 0 ? 0.5 * (1.0 + $erf) : 0.5 * (1.0 - $erf);
    }

    private function rpss(): null|float
    {
        $n = count($this->forecasts);

        if ($n === 0) {
            return null;
        }

        $climatology = [];
        $running = 0;

        foreach (range(1, 5) as $tier) {
            $running += $this->tierCounts[$tier] ?? 0;
            $climatology[] = $running / $n;
        }

        $model = 0.0;
        $reference = 0.0;

        foreach ($this->forecasts as [$cumulative, $measuredTier]) {
            foreach (range(1, 5) as $i => $tier) {
                $observed = $measuredTier <= $tier ? 1.0 : 0.0;
                $model += ($cumulative[$i] - $observed) ** 2;
                $reference += ($climatology[$i] - $observed) ** 2;
            }
        }

        return $reference > 0.0 ? round(1.0 - $model / $reference, self::DECIMALS) : null;
    }

    private static function tierOf(float $logDifficulty): int
    {
        return DifficultyTier::fromScore(exp($logDifficulty))->value;
    }

    /**
     * The tier boundaries in log difficulty, ascending - DifficultyTier::fromScore()'s (pinned by TierScoresTest)
     *
     * @return list<float>
     */
    public static function boundaries(): array
    {
        return self::$boundaries ??= [log(0.75), log(0.90), log(1.10), log(1.25), log(1.45)];
    }
}
