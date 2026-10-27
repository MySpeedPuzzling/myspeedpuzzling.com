<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;

/**
 * The candidate model's (v4) size curve (size-curve review 2026-10-27, docs/features/puzzle-intelligence/README.md
 * "Size curve"): how a player's solving time grows with the piece count, as one population curve in log time vs log
 * pieces through fixed knots (500 = 0), steeper below 500 for slower players (beta × (ln pace500 - ln 4200) × min(ln(s ÷
 * 500), 0)). Every direct pace the player has at another size is mapped through it to a "level" in 500 units; the
 * levels pooled by precision give a prior for the target size, which the player's own evidence there (their direct
 * pace row, or their 1-4 first tries) updates - more weight the farther from 500 (personal deviations grow with the
 * distance and do not carry across 500).
 *
 * Fitted daily by CandidateModelFitter (pairs regression of the window's first tries against the solvers' other-size
 * rows); pace(): the target's log pace (drift included) and its variance.
 */
readonly final class SizeCurve
{
    public const array DEFAULT_KNOTS = [48, 100, 150, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000];
    /** The review's fit (before 2026-04-01) - for tests and before the first fit */
    public const array DEFAULT_VALUES = [-3.089, -2.162, -1.590, -1.173, -0.675, 0.0, 0.629, 0.979, 1.626, 2.125, 2.687, 3.307];
    public const float REFERENCE_LOG_PACE_500 = 8.342839804; // ln(4200 s)

    /**
     * @param list<int> $knots piece counts, ascending, 500 among them
     * @param list<float> $values log time vs 500 at the knots (0.0 at 500)
     * @param array<string, int|float|string> $summary
     */
    public function __construct(
        public array $knots = self::DEFAULT_KNOTS,
        public array $values = self::DEFAULT_VALUES,
        public float $beta = 0.093,
        public float $tau2 = 0.005,
        public float $cPace = 1.57,
        public int $qCap = 50,
        public float $sigma1Squared = 0.03,
        public array $summary = [],
    ) {
    }

    /**
     * Log time at a piece count vs 500 for a player whose 500-piece pace is known (null = the speed term left out).
     */
    public function curve(int $pieces, null|float $logPace500 = null): float
    {
        $x = log(max($pieces, 1));
        $count = count($this->knots);
        $first = log($this->knots[0]);
        $last = log($this->knots[$count - 1]);
        $x = max($first, min($last, $x));
        $value = $this->values[$count - 1];

        for ($j = 0; $j < $count - 1; $j++) {
            $low = log($this->knots[$j]);
            $high = log($this->knots[$j + 1]);

            if ($x <= $high) {
                $t = ($x - $low) / ($high - $low);
                $value = (1 - $t) * $this->values[$j] + $t * $this->values[$j + 1];

                break;
            }
        }

        if ($logPace500 !== null) {
            $value += $this->beta * ($logPace500 - self::REFERENCE_LOG_PACE_500) * min(log($pieces / 500), 0.0);
        }

        return $value;
    }

    /**
     * The target's log pace (drift included) from the player's direct pace rows at every size and their 1-4 first tries
     * at the target size: [log pace, its variance, what it rests on, the spread of the closest row]. Null without any
     * evidence.
     *
     * @param list<array{0: int, 1: int, 2: float, 3: float, 4: int}> $rows [pieces, baseline, drift, spread, qualifying] of each direct row before the moment
     * @param null|array{0: int, 1: float} $few [n, Σ (ln seconds - log difficulty)] of 1-4 first tries at the target size (no direct row there)
     * @return null|array{0: float, 1: float, 2: PaceSource, 3: float}
     */
    public function pace(int $target, array $rows, null|array $few): null|array
    {
        $pace500 = null;
        $own = null;

        foreach ($rows as $row) {
            if ($row[0] === 500 && $row[1] > 0) {
                $pace500 = log($row[1]);
            }

            if ($row[0] === $target) {
                $own = $row;
            }
        }

        $precision = 0.0;
        $weighted = 0.0;
        $closest = null;

        foreach ($rows as $row) {
            [$pieces, $baseline, $drift, $spread] = $row;

            if ($pieces === $target || $baseline <= 0) {
                continue;
            }

            $variance = $this->rowVariance($spread, $row[4]) + $this->tau2 * log($pieces / 500) ** 2;
            $weighted += (log($baseline) + $drift - $this->curve($pieces, $pace500)) / $variance;
            $precision += 1.0 / $variance;
            $distance = abs(log($pieces / $target));

            if ($closest === null || $distance < $closest[0]) {
                $closest = [$distance, $spread];
            }
        }

        $observation = null;

        if ($own !== null && $own[1] > 0) {
            $observation = [log($own[1]) + $own[2], $this->rowVariance($own[3], $own[4]), $own[3]];
        } elseif ($few !== null && $few[0] > 0) {
            $observation = [$few[1] / $few[0], $this->sigma1Squared / $few[0], null];
        }

        if ($precision <= 0.0) {
            // No other size: own evidence alone (a direct row = today's own pace)
            if ($observation === null) {
                return null;
            }

            return [$observation[0], $observation[1], $own !== null ? PaceSource::Own : PaceSource::PooledFew, $observation[2] ?? PredictionModelParameters::GLOBAL_SPREAD];
        }

        $mean = $weighted / $precision + $this->curve($target, $pace500);
        $variance = 1.0 / $precision + $this->tau2 * log($target / 500) ** 2;
        $spread = $own[3] ?? ($closest[1] ?? PredictionModelParameters::GLOBAL_SPREAD);

        if ($observation === null) {
            return [$mean, $variance, PaceSource::Pooled, $spread];
        }

        $weight = $variance / ($variance + $observation[1]);

        return [
            $mean + $weight * ($observation[0] - $mean),
            $variance * $observation[1] / ($variance + $observation[1]),
            $own !== null ? PaceSource::PooledOwn : PaceSource::PooledFew,
            $spread,
        ];
    }

    /**
     * The distance band of a piece count from 500 (|ln(s ÷ 500)|) - the window's quantile key of a pooled pace.
     */
    public static function distanceBand(int $pieces): int
    {
        $distance = abs(log(max($pieces, 1) / 500));

        return match (true) {
            $distance < 0.01 => 0,
            $distance < 0.45 => 1,
            $distance < 0.75 => 2,
            $distance < 1.2 => 3,
            $distance < 1.7 => 4,
            default => 5,
        };
    }

    private function rowVariance(float $spread, int $qualifying): float
    {
        return $this->cPace * $spread ** 2 / max(min($qualifying, $this->qCap), PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) + 1e-4;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'knots' => $this->knots,
            'values' => $this->values,
            'beta' => $this->beta,
            'tau2' => $this->tau2,
            'c_pace' => $this->cPace,
            'q_cap' => $this->qCap,
            'sigma1_squared' => $this->sigma1Squared,
            'summary' => $this->summary,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): null|self
    {
        $knots = is_array($data['knots'] ?? null) ? array_values(array_map(static fn (mixed $knot): int => is_numeric($knot) ? (int) $knot : 0, $data['knots'])) : [];
        $values = is_array($data['values'] ?? null) ? array_values(array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, $data['values'])) : [];

        if (count($knots) < 2 || count($knots) !== count($values)) {
            return null;
        }

        $summary = [];

        foreach (is_array($data['summary'] ?? null) ? $data['summary'] : [] as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value) || is_string($value))) {
                $summary[$key] = $value;
            }
        }

        $number = static fn (string $key, float $default): float => is_numeric($data[$key] ?? null) ? (float) $data[$key] : $default;

        return new self(
            knots: $knots,
            values: $values,
            beta: $number('beta', 0.093),
            tau2: $number('tau2', 0.005),
            cPace: $number('c_pace', 1.57),
            qCap: (int) $number('q_cap', 50),
            sigma1Squared: $number('sigma1_squared', 0.03),
            summary: $summary,
        );
    }
}
