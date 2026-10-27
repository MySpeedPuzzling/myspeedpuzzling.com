<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Value\TasteModel;
use SpeedPuzzling\Web\Value\TasteState;
use SplFixedArray;

/**
 * Fits the candidate's taste factor (TasteModel) on the residuals of the window's first tries against the candidate's
 * centre (CandidateModelFitter::finish(), after the centre offsets):
 *
 * 1. W by alternating ridge regressions (ALS): each player's [b_i, u_i] given W (closed form, ridge lambdaB / lambdaU),
 *    then W given every u_i (one (RANK × FEATURES)² system, ridge LAMBDA_W) - from per-player sufficient statistics,
 *    so an iteration costs per player, not per first try;
 * 2. lambdaU by a held-out time split inside the window: W fitted on the oldest INNER_SHARE of it, each lambda scored on
 *    the newest rest as of every first try (the player's state from the rows before it) - how it is served;
 * 3. W once more on the whole window with that lambda - the served model - and every player's state after each of their
 *    rows for the caller to store (candidate_player_taste: the window's rows only);
 * 4. every row's term and variance as of its moment for the window's calibration, cross-fitted: from a W fitted without
 *    the row's player (two folds by player).
 *
 * Measured offline on the review's prod copy (README "The taste factor"): rank 4, lambdaB 30, LAMBDA_W 10 and lambdaU
 * from {30, 100, 300}; a higher rank or a weaker ridge over-fitted.
 */
final readonly class TasteFitter
{
    public const int MINIMUM_ROWS = 2000;
    public const int MINIMUM_PLAYERS = 30;
    public const array LAMBDA_U_GRID = [30.0, 100.0, 300.0];
    public const float LAMBDA_B = 30.0;
    public const float LAMBDA_W = 10.0;
    public const float COMPETITION_SHRINK = 10.0;
    public const float INNER_SHARE = 0.75;
    public const int ITERATIONS = 8;
    /** From a W fitted on similar rows (the full window from the inner fit, a fold from the full window) */
    public const int WARM_ITERATIONS = 4;
    /** A residual counts at most this much (log) - like the candidate's other fits */
    public const float RESIDUAL_CLIP = 1.0;

    /**
     * @param null|\Closure(int, float, float, TasteState): void $states every row's player, moment and state after it, in time order
     * @param list<array{0: null|int, 1: float, 2: float}> $queries [player, solved, tracked] whose state as of that moment (the rows strictly before it) the fit returns (TasteFit::$queryStates) - the unboxed first tries, which are no rows
     */
    public function fit(TasteRows $rows, string $factorsVersion, bool $competition, null|\Closure $states = null, array $queries = [], bool $slope = false): TasteFit
    {
        $count = $rows->count();
        $players = [];

        for ($r = 0; $r < $count; $r++) {
            $players[(int) $rows->player[$r]] = true;
        }

        $summary = ['taste_rows' => $count, 'taste_players' => count($players)];

        if ($count < self::MINIMUM_ROWS || count($players) < self::MINIMUM_PLAYERS) {
            return new TasteFit(null, null, null, [...$summary, 'taste_skipped' => 'too few first tries']);
        }

        $order = $rows->inTimeOrder();
        $innerCut = (float) $rows->solved[$order[(int) floor(self::INNER_SHARE * ($count - 1))]];
        $inner = array_values(array_filter($order, static fn (int $r): bool => (float) $rows->solved[$r] < $innerCut));

        $best = null;
        $statistics = $this->statistics($rows, $inner, $factorsVersion);

        $innerWeights = [];

        foreach (self::LAMBDA_U_GRID as $lambdaU) {
            $weights = $innerWeights[(string) $lambdaU] = $this->alternate($statistics, $lambdaU);
            $model = new TasteModel($factorsVersion, $weights, $lambdaU, self::LAMBDA_B, self::COMPETITION_SHRINK);
            [$error, $scored] = $this->asOfError($rows, $order, $model, $innerCut, $competition);
            $summary['taste_inner_mae_' . (int) $lambdaU] = round($error, 5);
            $summary['taste_inner_rows'] = $scored;

            if ($best === null || $error < $best[1]) {
                $best = [$lambdaU, $error];
            }
        }

        unset($statistics);
        $summary['taste_inner_mae_none'] = round($this->asOfError($rows, $order, null, $innerCut, $competition)[0], 5);
        $lambdaU = $best[0];
        // From the inner fit's W: the window's newest quarter moves it little
        $weights = $this->alternate($this->statistics($rows, $order, $factorsVersion), $lambdaU, $innerWeights[(string) $lambdaU], self::WARM_ITERATIONS);
        unset($innerWeights);
        $summary['taste_lambda_u'] = $lambdaU;
        $model = new TasteModel($factorsVersion, $weights, $lambdaU, self::LAMBDA_B, self::COMPETITION_SHRINK, $summary);

        // The window's calibration (CandidateModelFitter's window fit) reads every row's term from a W fitted without
        // the row's player (two folds by player): a W that saw the row's residual makes the term in-sample and the
        // window narrower than it holds after the cut (measured: 50 % hits 48.8 with the full W)
        $folds = [];

        foreach ([0, 1] as $fold) {
            $others = array_values(array_filter($order, static fn (int $r): bool => (int) $rows->player[$r] % 2 !== $fold));
            $folds[$fold] = new TasteModel($factorsVersion, $this->alternate($this->statistics($rows, $others, $factorsVersion), $lambdaU, $weights, self::WARM_ITERATIONS), $lambdaU, self::LAMBDA_B, self::COMPETITION_SHRINK);
        }

        /** @var SplFixedArray<float> $terms */
        $terms = new SplFixedArray($count);
        /** @var SplFixedArray<float> $variances */
        $variances = new SplFixedArray($count);
        $crossState = [];

        foreach ($order as $r) {
            $player = (int) $rows->player[$r];
            $foldModel = $folds[$player % 2];
            $vector = $foldModel->vector($rows->picture($r), (int) $rows->pieces[$r]);
            $before = $crossState[$player] ?? $foldModel->initialState();
            $atEvent = $rows->competition[$r] === true;
            $logDifficulty = $slope ? $rows->logDifficulty[$r] : null;
            $terms[$r] = $before->term($vector) + ($competition && $atEvent ? $before->competitionOffset($foldModel->competitionShrink) : 0.0)
                + ($logDifficulty !== null ? $before->slopeTerm($logDifficulty, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM) : 0.0);
            $variances[$r] = $before->variance($vector);
            $crossState[$player] = $before->with($vector, (float) $rows->residual[$r], $atEvent, $logDifficulty);
        }

        unset($crossState, $folds);

        // The served states: the full W
        /** @var array<int, TasteState> $state */
        $state = [];
        $queryOrder = array_keys($queries);
        usort($queryOrder, static fn (int $a, int $b): int => $queries[$a][1] <=> $queries[$b][1] ?: $queries[$a][2] <=> $queries[$b][2]);
        $queryStates = [];
        $next = 0;
        $answer = static function (float $solved, float $tracked) use (&$next, &$queryStates, &$state, $queryOrder, $queries, $model): void {
            while ($next < count($queryOrder)) {
                [$player, $querySolved, $queryTracked] = $queries[$queryOrder[$next]];

                if (($querySolved <=> $solved ?: $queryTracked <=> $tracked) > 0) {
                    return;
                }

                $queryStates[$queryOrder[$next]] = $player !== null ? ($state[$player] ?? $model->initialState()) : $model->initialState();
                $next++;
            }
        };

        foreach ($order as $r) {
            $answer((float) $rows->solved[$r], (float) $rows->tracked[$r]);
            $player = (int) $rows->player[$r];
            $vector = $model->vector($rows->picture($r), (int) $rows->pieces[$r]);
            $state[$player] = ($state[$player] ?? $model->initialState())->with($vector, (float) $rows->residual[$r], $rows->competition[$r] === true, $rows->logDifficulty[$r]);

            if ($states !== null) {
                $states($player, (float) $rows->solved[$r], (float) $rows->tracked[$r], $state[$player]);
            }
        }

        $answer(INF, INF);

        return new TasteFit($model, $terms, $variances, $summary, $queryStates);
    }

    /**
     * Per player over the rows: n, Σ r, Σ x, Σ x xᵀ (packed upper triangle), Σ x r.
     *
     * @param list<int> $rowsUsed
     * @return array<int, array{0: int, 1: float, 2: list<float>, 3: list<float>, 4: list<float>}>
     */
    private function statistics(TasteRows $rows, array $rowsUsed, string $factorsVersion): array
    {
        $size = TasteModel::FEATURES;
        $statistics = [];
        $features = [];

        foreach ($rowsUsed as $r) {
            $player = (int) $rows->player[$r];
            $puzzle = (int) $rows->puzzle[$r];
            $x = $features[$puzzle] ??= TasteModel::features($rows->picture($r), (int) $rows->pieces[$r], $factorsVersion);
            $residual = (float) $rows->residual[$r];
            $statistics[$player] ??= [0, 0.0, array_fill(0, $size, 0.0), array_fill(0, $size * ($size + 1) / 2, 0.0), array_fill(0, $size, 0.0)];
            $entry = &$statistics[$player];
            $entry[0]++;
            $entry[1] += $residual;
            $index = 0;

            for ($i = 0; $i < $size; $i++) {
                $xi = $x[$i];
                $entry[2][$i] += $xi;
                $entry[4][$i] += $xi * $residual;

                if ($xi == 0.0) {
                    $index += $size - $i;

                    continue;
                }

                for ($j = $i; $j < $size; $j++) {
                    $entry[3][$index++] += $xi * $x[$j];
                }
            }

            unset($entry);
        }

        return $statistics;
    }

    /**
     * W by alternating ridge regressions from a fixed start (the fit is reproducible) or from a W already fitted on
     * similar rows (fewer iterations). Every product with a player's Σ x xᵀ runs over its packed upper triangle.
     *
     * @param array<int, array{0: int, 1: float, 2: list<float>, 3: list<float>, 4: list<float>}> $statistics
     * @param null|list<list<float>> $start
     * @return list<list<float>>
     */
    private function alternate(array $statistics, float $lambdaU, null|array $start = null, int $iterations = self::ITERATIONS): array
    {
        $rank = TasteModel::RANK;
        $size = TasteModel::FEATURES;
        $weights = $start ?? [];

        if ($start === null) {
            for ($k = 0; $k < $rank; $k++) {
                for ($j = 0; $j < $size; $j++) {
                    $weights[$k][$j] = 0.1 * sin(1.0 + 3.7 * $k + 1.3 * $j);
                }
            }
        }

        // The packed triangle's (i, j) pairs, once
        $pairs = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = $i; $j < $size; $j++) {
                $pairs[] = [$i, $j];
            }
        }

        $packedSize = count($pairs);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $blocks = [];

            for ($k = 0; $k < $rank; $k++) {
                for ($l = $k; $l < $rank; $l++) {
                    $blocks[$k][$l] = array_fill(0, $packedSize, 0.0);
                }
            }

            $moment = array_fill(0, $rank * $size, 0.0);

            foreach ($statistics as [$n, $residualSum, $featureSum, $packed, $moments]) {
                // W S, from the packed triangle
                $ws = array_fill(0, $rank, array_fill(0, $size, 0.0));

                foreach ($packed as $index => $value) {
                    if ($value == 0.0) {
                        continue;
                    }

                    [$i, $j] = $pairs[$index];

                    for ($k = 0; $k < $rank; $k++) {
                        $ws[$k][$j] += $weights[$k][$i] * $value;

                        if ($i !== $j) {
                            $ws[$k][$i] += $weights[$k][$j] * $value;
                        }
                    }
                }

                // The player's [b, u] given W
                $matrix = array_fill(0, $rank + 1, array_fill(0, $rank + 1, 0.0));
                $rhs = [$residualSum];
                $matrix[0][0] = $n + self::LAMBDA_B;

                for ($k = 0; $k < $rank; $k++) {
                    $wx = 0.0;
                    $wt = 0.0;

                    foreach ($weights[$k] as $j => $weight) {
                        $wx += $weight * $featureSum[$j];
                        $wt += $weight * $moments[$j];
                    }

                    $matrix[0][$k + 1] = $matrix[$k + 1][0] = $wx;
                    $rhs[] = $wt;

                    for ($l = 0; $l < $rank; $l++) {
                        $sum = 0.0;

                        foreach ($weights[$l] as $j => $weight) {
                            $sum += $ws[$k][$j] * $weight;
                        }

                        $matrix[$k + 1][$l + 1] = $sum + ($k === $l ? $lambdaU : 0.0);
                    }
                }

                $solution = self::solve($matrix, $rhs);
                $intercept = $solution[0];

                // W given u: Σ (u uᵀ) ⊗ S (blocks k <= l, packed), Σ u ⊗ (Σ x r - b Σ x)
                for ($k = 0; $k < $rank; $k++) {
                    $uk = $solution[$k + 1];

                    for ($j = 0; $j < $size; $j++) {
                        $moment[$k * $size + $j] += $uk * ($moments[$j] - $intercept * $featureSum[$j]);
                    }

                    for ($l = $k; $l < $rank; $l++) {
                        $ukl = $uk * $solution[$l + 1];
                        $block = &$blocks[$k][$l];

                        foreach ($packed as $index => $value) {
                            $block[$index] += $ukl * $value;
                        }

                        unset($block);
                    }
                }
            }

            $dimension = $rank * $size;
            $gram = array_fill(0, $dimension, array_fill(0, $dimension, 0.0));

            foreach ($blocks as $k => $byL) {
                foreach ($byL as $l => $block) {
                    foreach ($block as $index => $value) {
                        [$i, $j] = $pairs[$index];
                        $gram[$k * $size + $i][$l * $size + $j] = $gram[$l * $size + $j][$k * $size + $i] = $value;
                        $gram[$k * $size + $j][$l * $size + $i] = $gram[$l * $size + $i][$k * $size + $j] = $value;
                    }
                }
            }

            for ($a = 0; $a < $dimension; $a++) {
                $gram[$a][$a] += self::LAMBDA_W;
            }

            $solution = self::solve($gram, $moment);

            for ($k = 0; $k < $rank; $k++) {
                for ($j = 0; $j < $size; $j++) {
                    $weights[$k][$j] = round($solution[$k * $size + $j], 8);
                }
            }
        }

        /** @var list<list<float>> $weights */
        return $weights;
    }

    /**
     * The mean absolute residual of the rows from $from on after the term as of each (the player's state from every
     * earlier row) - without a model: the residuals themselves.
     *
     * @param list<int> $order
     * @return array{0: float, 1: int}
     */
    private function asOfError(TasteRows $rows, array $order, null|TasteModel $model, float $from, bool $competition): array
    {
        $state = [];
        $error = 0.0;
        $scored = 0;

        foreach ($order as $r) {
            $residual = (float) $rows->residual[$r];
            $atEvent = $rows->competition[$r] === true;

            if ($model === null) {
                $term = 0.0;
                $vector = null;
            } else {
                $player = (int) $rows->player[$r];
                $vector = $model->vector($rows->picture($r), (int) $rows->pieces[$r]);
                $before = $state[$player] ?? $model->initialState();
                $term = $before->term($vector) + ($competition && $atEvent ? $before->competitionOffset($model->competitionShrink) : 0.0);
                $state[$player] = $before->with($vector, $residual, $atEvent);
            }

            if ((float) $rows->solved[$r] >= $from) {
                $error += abs($residual - $term);
                $scored++;
            }
        }

        return [$scored > 0 ? $error / $scored : 0.0, $scored];
    }

    /**
     * Gaussian elimination with partial pivoting (a symmetric positive definite system: the ridge keeps it regular).
     *
     * @param array<int, array<int, float>> $matrix
     * @param array<int, float> $rhs
     * @return list<float>
     */
    public static function solve(array $matrix, array $rhs): array
    {
        $size = count($rhs);

        foreach ($matrix as $row => $line) {
            $matrix[$row][$size] = $rhs[$row];
        }

        for ($pivot = 0; $pivot < $size; $pivot++) {
            $best = $pivot;

            for ($row = $pivot + 1; $row < $size; $row++) {
                if (abs($matrix[$row][$pivot]) > abs($matrix[$best][$pivot])) {
                    $best = $row;
                }
            }

            [$matrix[$pivot], $matrix[$best]] = [$matrix[$best], $matrix[$pivot]];
            $diagonal = $matrix[$pivot][$pivot];

            if (abs($diagonal) < 1e-12) {
                continue;
            }

            for ($row = $pivot + 1; $row < $size; $row++) {
                $factor = $matrix[$row][$pivot] / $diagonal;

                if ($factor == 0.0) {
                    continue;
                }

                for ($column = $pivot; $column <= $size; $column++) {
                    $matrix[$row][$column] -= $factor * $matrix[$pivot][$column];
                }
            }
        }

        $solution = array_fill(0, $size, 0.0);

        for ($row = $size - 1; $row >= 0; $row--) {
            if (abs($matrix[$row][$row]) < 1e-12) {
                continue;
            }

            $value = $matrix[$row][$size];

            for ($column = $row + 1; $column < $size; $column++) {
                $value -= $matrix[$row][$column] * $solution[$column];
            }

            $solution[$row] = $value / $matrix[$row][$row];
        }

        return array_values($solution);
    }
}
