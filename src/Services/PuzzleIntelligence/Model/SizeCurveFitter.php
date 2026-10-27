<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Value\SizeCurve;
use SplFixedArray;

/**
 * Fits the candidate model's size curve (SizeCurve, size-curve review 2026-10-27 §3) on the first tries of the fit window:
 *
 * 1. pairs: every first try i with every direct pace row r of the solver at another piece count valid before it,
 *    y = ln t - log difficulty - ln baseline_r - drift_r against the hat basis of the knots B(s_i) - B(s_r) (500 = 0) and
 *    the speed column (ln pace500 - ln 4200) × (min(ln(s_i ÷ 500), 0) - min(ln(s_r ÷ 500), 0)), each first try weighing 1
 *    in all (1 ÷ its rows); robust (Huber 0.25, IRLS) with a ridge on the knot values' second differences (sparse knots
 *    stay smooth);
 * 2. tau² × sigma1² on a grid by the error of the pooled pace on **held-out** first tries with another size: the players
 *    of one fold in five (by their id) are left out of a first regression and the grid is scored on them with its curve;
 *    the curve itself is then fitted on everybody (tau² and sigma1² do not enter the regression).
 *
 * A curve that cannot be identified (too few pairs, a singular system) is not returned: NotIdentifiable - the caller keeps
 * the curve it has (CandidateModelFitter::fitSizeCurve()).
 */
final readonly class SizeCurveFitter
{
    public const array TAU2_GRID = [0.003, 0.005, 0.008, 0.012];
    public const array SIGMA1_GRID = [0.015, 0.02, 0.03, 0.05];
    private const float HUBER = 0.25;
    private const int ITERATIONS = 10;
    private const float RIDGE = 20.0;
    private const int MINIMUM_PAIRS = 2000;
    // A subsample is enough for 16 grid points - and the fit runs in 512 MB
    private const int GRID_SAMPLE = 15000;
    // Both piece counts of a pair in one int (target × this + the row's)
    private const int PIECES_FACTOR = 100000;
    // A pair's code: (both piece counts) × this + its first try's rows (≤ 63) × 2 + held out
    private const int CODE_FACTOR = 128;
    // One fold in this many is held out for the grid (by player)
    public const int FOLDS = 5;
    private const float SINGULAR = 1e-12;

    /**
     * The held-out fold of a player (0 = held out for the grid).
     */
    public static function foldOf(string $playerId): int
    {
        return crc32($playerId) % self::FOLDS;
    }

    /**
     * @param iterable<array{0: int, 1: float, 2: list<array{0: int, 1: int, 2: float, 3: float, 4: int}>, 3: null|array{0: int, 1: float}, 4: int}> $firstTries [pieces, ln t - log difficulty, the solver's rows, own few, the player's fold - foldOf()] per first try of the window - read once
     * @param int $count how many there are (the grid keeps every n-th held-out one)
     * @throws NotIdentifiable too few pairs or a singular system
     */
    public function fit(iterable $firstTries, int $count, SizeCurve $start = new SizeCurve()): SizeCurve
    {
        $knots = $start->knots;
        $reference = array_search(500, $knots, true);

        if (!is_int($reference)) {
            throw new \InvalidArgumentException('The size curve needs a knot at 500 pieces (its fixed point)');
        }

        $columns = count($knots) - 1 + 1; // the knots but 500, the speed term

        // The pairs, three fixed-size columns grown by doubling and cut to size (a PHP list holds up to twice its values;
        // the fit runs in a 512 MB container): the code (both piece counts, the first try's number of rows, held out),
        // y and the speed column
        // ~1.8 pairs per first try on the production copy
        $capacity = max(1024, 2 * $count);
        /** @var SplFixedArray<int> $code */
        $code = new SplFixedArray($capacity);
        /** @var SplFixedArray<float> $y */
        $y = new SplFixedArray($capacity);
        /** @var SplFixedArray<float> $speed */
        $speed = new SplFixedArray($capacity);
        $pairs = 0;
        $knotPairs = array_fill(0, count($knots), 0);
        // The grid's subsample of the held-out first tries with another size, kept while the pairs are collected
        $step = max(1, intdiv(intdiv($count, self::FOLDS), self::GRID_SAMPLE));
        $withOthers = [];
        $seen = 0;
        $seenHeldOut = 0;

        foreach ($firstTries as $firstTry) {
            [$pieces, $adjusted, $rows] = $firstTry;
            $others = array_values(array_filter($rows, static fn (array $row): bool => $row[0] !== $pieces && $row[1] > 0));

            if ($others === []) {
                continue;
            }

            $seen++;
            $isHeldOut = $firstTry[4] === 0;

            if ($isHeldOut && $seenHeldOut++ % $step === 0) {
                $withOthers[] = $firstTry;
            }

            $pace500 = null;

            foreach ($rows as $row) {
                if ($row[0] === 500 && $row[1] > 0) {
                    $pace500 = log($row[1]) - SizeCurve::REFERENCE_LOG_PACE_500;
                }
            }

            foreach ($others as $row) {
                if ($pairs === $code->getSize()) {
                    foreach ([$code, $y, $speed] as $column) {
                        $column->setSize(2 * $pairs);
                    }
                }

                $code[$pairs] = self::code($pieces * self::PIECES_FACTOR + $row[0], count($others), $isHeldOut);
                $y[$pairs] = $adjusted - log($row[1]) - $row[2];
                $speed[$pairs] = $pace500 !== null ? $pace500 * (min(log($pieces / 500), 0.0) - min(log($row[0] / 500), 0.0)) : 0.0;
                $pairs++;
            }
        }

        foreach ([$code, $y, $speed] as $column) {
            $column->setSize($pairs);
        }

        if ($pairs < self::MINIMUM_PAIRS) {
            throw new NotIdentifiable(sprintf('%d pairs, at least %d needed', $pairs, self::MINIMUM_PAIRS));
        }

        // The knot part of a pair's row is sparse (two hat functions) and the same for every pair of the same two sizes:
        // one sparse row per size combination, the speed column apart
        $sparse = [];
        /** @var \Closure(int): array<int, float> $rowsOf */
        $rowsOf = function (int $pair) use (&$sparse, $code, $speed, $knots, $reference, $columns): array {
            $key = intdiv((int) $code[$pair], self::CODE_FACTOR);

            if (!isset($sparse[$key])) {
                $basis = self::basis(intdiv($key, self::PIECES_FACTOR), $knots);
                $other = self::basis($key % self::PIECES_FACTOR, $knots);
                $entries = [];
                $column = 0;

                foreach ($basis as $j => $value) {
                    if ($j === $reference) {
                        continue;
                    }

                    if ($value - $other[$j] != 0.0) {
                        $entries[$column] = $value - $other[$j];
                    }

                    $column++;
                }

                $sparse[$key] = $entries;
            }

            $row = $sparse[$key];

            $speedValue = (float) $speed[$pair];

            if ($speedValue != 0.0) {
                $row[$columns - 1] = $speedValue;
            }

            return $row;
        };

        foreach ($code as $pairCode) {
            $key = intdiv((int) $pairCode, self::CODE_FACTOR);

            foreach ([self::basis(intdiv($key, self::PIECES_FACTOR), $knots), self::basis($key % self::PIECES_FACTOR, $knots)] as $basis) {
                foreach ($basis as $j => $value) {
                    if ($value > 0.0) {
                        $knotPairs[$j]++;
                    }
                }
            }
        }

        // The ridge on the second differences of the full knot values (500 fixed at 0, the speed term free)
        $penalty = array_fill(0, $columns, array_fill(0, $columns, 0.0));
        $knotCount = count($knots);

        for ($j = 1; $j < $knotCount - 1; $j++) {
            $difference = array_fill(0, $knotCount, 0.0);
            $difference[$j - 1] = 1.0;
            $difference[$j] = -2.0;
            $difference[$j + 1] = 1.0;
            $reduced = array_values(array_filter($difference, static fn (int $index): bool => $index !== $reference, ARRAY_FILTER_USE_KEY));

            foreach ($reduced as $a => $valueA) {
                foreach ($reduced as $b => $valueB) {
                    $penalty[$a][$b] += self::RIDGE * $valueA * $valueB;
                }
            }
        }

        // Robust regression on the pairs (all of them, or without the held-out fold's)
        /** @var \Closure(bool): array<int, float> $regress */
        $regress = static function (bool $withHeldOut) use ($penalty, $pairs, $rowsOf, $y, $code, $columns): array {
            // Each first try weighs 1 in all (1 ÷ its rows), then the Huber weights
            /** @var SplFixedArray<float> $weights */
            $weights = new SplFixedArray($pairs);

            for ($pair = 0; $pair < $pairs; $pair++) {
                $weights[$pair] = self::baseWeight((int) $code[$pair]);
            }

            $coefficients = array_fill(0, $columns, 0.0);

            for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
                $matrix = $penalty;
                $vector = array_fill(0, $columns, 0.0);

                for ($pair = 0; $pair < $pairs; $pair++) {
                    if ($withHeldOut === false && self::isHeldOut((int) $code[$pair])) {
                        continue;
                    }

                    $row = $rowsOf($pair);
                    $weight = (float) $weights[$pair];
                    $target = (float) $y[$pair];

                    foreach ($row as $a => $valueA) {
                        $vector[$a] += $weight * $valueA * $target;

                        foreach ($row as $b => $valueB) {
                            $matrix[$a][$b] += $weight * $valueA * $valueB;
                        }
                    }
                }

                $coefficients = self::solve($matrix, $vector);

                for ($pair = 0; $pair < $pairs; $pair++) {
                    $prediction = 0.0;

                    foreach ($rowsOf($pair) as $a => $value) {
                        $prediction += $value * $coefficients[$a];
                    }

                    $weights[$pair] = self::baseWeight((int) $code[$pair]) * min(1.0, self::HUBER / max(abs((float) $y[$pair] - $prediction), 1e-9));
                }
            }

            return $coefficients;
        };

        // tau² × sigma1² by the pooled pace's error on the held-out players' first tries with another size, with the
        // curve fitted without them
        $trained = $regress(false);
        $trainedValues = self::valuesOf($trained, $knots, $reference);
        $best = null;

        foreach (self::TAU2_GRID as $tau2) {
            foreach (self::SIGMA1_GRID as $sigma1) {
                $candidate = new SizeCurve($knots, $trainedValues, round($trained[$columns - 1], 4), $tau2, $start->cPace, $start->qCap, $sigma1);
                $error = 0.0;
                $scored = 0;

                foreach ($withOthers as [$pieces, $adjusted, $rows, $few]) {
                    $pace = $candidate->pace($pieces, $rows, $few);

                    if ($pace !== null) {
                        $error += abs($adjusted - $pace[0]);
                        $scored++;
                    }
                }

                if ($scored > 0 && ($best === null || $error / $scored < $best[2])) {
                    $best = [$tau2, $sigma1, $error / $scored];
                }
            }
        }

        // The curve itself on everybody
        $coefficients = $regress(true);
        $values = self::valuesOf($coefficients, $knots, $reference);
        $curve = new SizeCurve(knots: $knots, values: $values, beta: round($coefficients[$columns - 1], 4), tau2: $start->tau2, cPace: $start->cPace, qCap: $start->qCap, sigma1Squared: $start->sigma1Squared);

        $summary = ['size_curve_pairs' => $pairs, 'size_curve_first_tries' => $seen, 'size_curve_held_out' => $seenHeldOut];

        foreach ($knots as $j => $knot) {
            $summary['size_curve_pairs_at_' . $knot] = $knotPairs[$j];
        }

        if ($best !== null) {
            $summary['size_curve_held_out_mae'] = round($best[2], 4);
        }

        return new SizeCurve($knots, $values, $curve->beta, $best[0] ?? $start->tau2, $curve->cPace, $curve->qCap, $best[1] ?? $start->sigma1Squared, $summary);
    }

    private static function code(int $piecesPair, int $rows, bool $heldOut): int
    {
        return $piecesPair * self::CODE_FACTOR + min($rows, 63) * 2 + ($heldOut ? 1 : 0);
    }

    private static function baseWeight(int $code): float
    {
        return 1.0 / intdiv($code % self::CODE_FACTOR, 2);
    }

    private static function isHeldOut(int $code): bool
    {
        return $code % 2 === 1;
    }

    /**
     * The knot values of the coefficients (500 fixed at 0).
     *
     * @param array<int, float> $coefficients
     * @param list<int> $knots
     * @return list<float>
     */
    private static function valuesOf(array $coefficients, array $knots, int $reference): array
    {
        $values = [];
        $column = 0;

        foreach ($knots as $j => $knot) {
            $values[] = $j === $reference ? 0.0 : round($coefficients[$column++], 4);
        }

        return $values;
    }

    /**
     * The hat basis of a piece count over the knots (log pieces, clamped to the end knots).
     *
     * @param list<int> $knots
     * @return array<int, float>
     */
    public static function basis(int $pieces, array $knots): array
    {
        $count = count($knots);
        $basis = array_fill(0, $count, 0.0);
        $x = max(log($knots[0]), min(log($knots[$count - 1]), log(max($pieces, 1))));

        for ($j = 0; $j < $count - 1; $j++) {
            $low = log($knots[$j]);
            $high = log($knots[$j + 1]);

            if ($x <= $high) {
                $t = ($x - $low) / ($high - $low);
                $basis[$j] = 1 - $t;
                $basis[$j + 1] = $t;

                return $basis;
            }
        }

        $basis[$count - 1] = 1.0;

        return $basis;
    }

    /**
     * Gaussian elimination with partial pivoting (a small dense system).
     *
     * @param array<int, array<int, float>> $matrix
     * @param array<int, float> $vector
     * @return array<int, float>
     * @throws NotIdentifiable a (numerically) singular system: a knot without pairs the ridge cannot carry
     */
    private static function solve(array $matrix, array $vector): array
    {
        $n = count($vector);

        for ($column = 0; $column < $n; $column++) {
            $pivot = $column;

            for ($row = $column + 1; $row < $n; $row++) {
                if (abs($matrix[$row][$column]) > abs($matrix[$pivot][$column])) {
                    $pivot = $row;
                }
            }

            [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];
            [$vector[$column], $vector[$pivot]] = [$vector[$pivot], $vector[$column]];

            if (abs($matrix[$column][$column]) < self::SINGULAR) {
                throw new NotIdentifiable(sprintf('A singular system at column %d', $column));
            }

            for ($row = $column + 1; $row < $n; $row++) {
                $factor = $matrix[$row][$column] / $matrix[$column][$column];

                for ($k = $column; $k < $n; $k++) {
                    $matrix[$row][$k] -= $factor * $matrix[$column][$k];
                }

                $vector[$row] -= $factor * $vector[$column];
            }
        }

        $solution = array_fill(0, $n, 0.0);

        for ($row = $n - 1; $row >= 0; $row--) {
            $sum = $vector[$row];

            for ($k = $row + 1; $k < $n; $k++) {
                $sum -= $matrix[$row][$k] * $solution[$k];
            }

            $solution[$row] = $sum / $matrix[$row][$row];
        }

        return $solution;
    }
}
