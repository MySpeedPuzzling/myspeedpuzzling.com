<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One player's taste as of a moment (TasteModel): the ridge solution's sufficient statistics over their first tries
 * before it, in the model's space v = [1, W x] - A = prior + Σ v vᵀ (packed upper triangle), g = Σ v r (r = the first
 * try's residual against the candidate's centre without the taste, clipped) - plus Σ (r - their taste term then) and
 * the count of their first tries at events (the event offset). Coefficients = A⁻¹ g, closed form: a player with nothing
 * known has every term 0, a few first tries move it little (shrunk toward 0), many settle it. Stored as of every first
 * try (candidate_player_taste), so a reconstruction reads exactly the state before its solve.
 *
 * v7: the player's own difficulty slope (slopeTerm()) from five more sums over the same first tries - the puzzle's log
 * difficulty d and the residual left after the taste term s: n, Σ d, Σ d², Σ s, Σ d s.
 */
readonly final class TasteState
{
    /** @var list<float> A⁻¹ g */
    public array $coefficients;

    /** @var list<list<float>> the Cholesky factor L of A (A = L Lᵀ) */
    private array $cholesky;

    /**
     * @param list<float> $a A, packed upper triangle row by row (a₀₀, a₀₁, …, a₁₁, …)
     * @param list<float> $g
     */
    public function __construct(
        public int $firstTries,
        public array $a,
        public array $g,
        public float $competitionSum = 0.0,
        public int $competitionCount = 0,
        /** @var array{0: int, 1: float, 2: float, 3: float, 4: float} v7: n, Σ d, Σ d², Σ s, Σ d s (slopeTerm()) */
        public array $slope = [0, 0.0, 0.0, 0.0, 0.0],
    ) {
        $this->cholesky = self::choleskyOf($a, count($g));
        $this->coefficients = $this->solve($g);
    }

    public static function initial(float $lambdaB, float $lambdaU, int $rank): self
    {
        $size = $rank + 1;
        $a = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = $i; $j < $size; $j++) {
                $a[] = $i === $j ? ($i === 0 ? $lambdaB : $lambdaU) : 0.0;
            }
        }

        return new self(0, $a, array_fill(0, $size, 0.0));
    }

    /**
     * The player's taste term for a puzzle vector (TasteModel::vector()) - their log offset there.
     *
     * @param list<float> $vector
     */
    public function term(array $vector): float
    {
        $sum = 0.0;

        foreach ($this->coefficients as $i => $coefficient) {
            $sum += $coefficient * $vector[$i];
        }

        return $sum;
    }

    /**
     * vᵀ A⁻¹ v - the term's uncertainty (× the residual variance; the window fits its weight).
     *
     * @param list<float> $vector
     */
    public function variance(array $vector): float
    {
        $size = count($this->g);
        $y = [];
        $sum = 0.0;

        // L y = v, then vᵀ A⁻¹ v = |y|²
        for ($i = 0; $i < $size; $i++) {
            $value = $vector[$i];

            for ($k = 0; $k < $i; $k++) {
                $value -= $this->cholesky[$i][$k] * $y[$k];
            }

            $y[$i] = $value / $this->cholesky[$i][$i];
            $sum += $y[$i] ** 2;
        }

        return $sum;
    }

    /**
     * The player's offset at an event: their residuals there less their taste term, shrunk toward 0.
     */
    public function competitionOffset(float $shrink): float
    {
        return $this->competitionSum / ($this->competitionCount + $shrink);
    }

    /**
     * v7: the player's own difficulty slope on a puzzle of log difficulty d - γ (d - their mean d), γ = Σ (d - mean)
     * s ÷ (Σ (d - mean)² + lambda): some players lose relatively more on hard puzzles than others (data-mining F7; held
     * on both time splits). 0 below $minimum first tries with a difficulty.
     */
    public function slopeTerm(float $logDifficulty, float $lambda, int $minimum): float
    {
        [$n, $sum, $squares, $residuals, $products] = $this->slope;

        if ($n < $minimum || $n === 0) {
            return 0.0;
        }

        $mean = $sum / $n;
        $gamma = ($products - $mean * $residuals) / (max($squares - $n * $mean * $mean, 0.0) + $lambda);

        return $gamma * ($logDifficulty - $mean);
    }

    /**
     * The state after one more first try: its vector, its residual (clipped by the caller) and whether it was at an
     * event (its residual less the term known before it counts toward the event offset); with the puzzle's log
     * difficulty (v7) the slope's sums too, from the residual less the taste term known before it.
     *
     * @param list<float> $vector
     */
    public function with(array $vector, float $residual, bool $competition, null|float $logDifficulty = null): self
    {
        $a = $this->a;
        $g = $this->g;
        $size = count($g);
        $index = 0;

        for ($i = 0; $i < $size; $i++) {
            for ($j = $i; $j < $size; $j++) {
                $a[$index++] += $vector[$i] * $vector[$j];
            }

            $g[$i] += $vector[$i] * $residual;
        }

        $slope = $this->slope;

        if ($logDifficulty !== null) {
            $left = $residual - $this->term($vector);
            $slope = [$slope[0] + 1, $slope[1] + $logDifficulty, $slope[2] + $logDifficulty ** 2, $slope[3] + $left, $slope[4] + $logDifficulty * $left];
        }

        return new self(
            $this->firstTries + 1,
            array_values($a),
            array_values($g),
            $this->competitionSum + ($competition ? $residual - $this->term($vector) : 0.0),
            $this->competitionCount + ($competition ? 1 : 0),
            $slope,
        );
    }

    /**
     * @return array{n: int, a: list<float>, g: list<float>, cs: float, cn: int, s?: array{0: int, 1: float, 2: float, 3: float, 4: float}}
     */
    public function toArray(): array
    {
        $data = ['n' => $this->firstTries, 'a' => $this->a, 'g' => $this->g, 'cs' => $this->competitionSum, 'cn' => $this->competitionCount];

        if ($this->slope[0] > 0) {
            $data['s'] = $this->slope;
        }

        return $data;
    }

    /**
     * From the stored JSON (toArray()) - null when it does not fit a model of this rank.
     */
    public static function fromJson(mixed $json, int $rank): null|self
    {
        $data = is_string($json) ? json_decode($json, true) : $json;
        $size = $rank + 1;

        if (
            !is_array($data) || !is_array($data['a'] ?? null) || !is_array($data['g'] ?? null)
            || count($data['g']) !== $size || count($data['a']) !== $size * ($size + 1) / 2
        ) {
            return null;
        }

        $floats = static fn (array $values): array => array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, array_values($values));

        return new self(
            is_numeric($data['n'] ?? null) ? (int) $data['n'] : 0,
            $floats($data['a']),
            $floats($data['g']),
            is_numeric($data['cs'] ?? null) ? (float) $data['cs'] : 0.0,
            is_numeric($data['cn'] ?? null) ? (int) $data['cn'] : 0,
            self::slopeOf($data['s'] ?? null),
        );
    }

    /**
     * @return array{0: int, 1: float, 2: float, 3: float, 4: float}
     */
    private static function slopeOf(mixed $stored): array
    {
        if (!is_array($stored) || count($stored) !== 5) {
            return [0, 0.0, 0.0, 0.0, 0.0];
        }

        $values = array_values(array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, $stored));

        return [(int) $values[0], $values[1], $values[2], $values[3], $values[4]];
    }

    /**
     * @param list<float> $b
     * @return list<float> A⁻¹ b
     */
    private function solve(array $b): array
    {
        $size = count($b);
        $y = [];

        for ($i = 0; $i < $size; $i++) {
            $value = $b[$i];

            for ($k = 0; $k < $i; $k++) {
                $value -= $this->cholesky[$i][$k] * $y[$k];
            }

            $y[$i] = $value / $this->cholesky[$i][$i];
        }

        $x = array_fill(0, $size, 0.0);

        for ($i = $size - 1; $i >= 0; $i--) {
            $value = $y[$i];

            for ($k = $i + 1; $k < $size; $k++) {
                $value -= $this->cholesky[$k][$i] * $x[$k];
            }

            $x[$i] = $value / $this->cholesky[$i][$i];
        }

        return array_values($x);
    }

    /**
     * @param list<float> $packed
     * @return list<list<float>>
     */
    private static function choleskyOf(array $packed, int $size): array
    {
        $full = [];
        $index = 0;

        for ($i = 0; $i < $size; $i++) {
            for ($j = $i; $j < $size; $j++) {
                $full[$i][$j] = $full[$j][$i] = $packed[$index++];
            }
        }

        $lower = array_fill(0, $size, array_fill(0, $size, 0.0));

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j <= $i; $j++) {
                $sum = $full[$i][$j];

                for ($k = 0; $k < $j; $k++) {
                    $sum -= $lower[$i][$k] * $lower[$j][$k];
                }

                // A is the prior (positive) plus outer products - positive definite; the floor only guards rounding
                $lower[$i][$j] = $i === $j ? sqrt(max($sum, 1e-12)) : $sum / $lower[$j][$j];
            }
        }

        return array_map(array_values(...), $lower);
    }
}
