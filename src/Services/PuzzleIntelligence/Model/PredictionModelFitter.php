<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * Fits PredictionModelParameters on the first attempts and repeats solved in a window (the batch: the last 12 months,
 * daily; the evaluation: the 12 months before its cut-off), each predicted as of its own moment by PredictionEngine.
 * Every part falls back to its default when the window holds too few samples for it.
 *
 * 1. tau - the prior's pseudo-count in the shrunk difficulty: the candidate with the lowest error on attempts at
 *    puzzles with 1-4 indices;
 * 2. drift and spread with that tau (PredictionEngine::computeDrift());
 * 3. thin sizes - per piece-count group the size exponent (own direct pace vs 500-piece pace of the same players) and
 *    the number of own first attempts below which the 500-scaled pace predicts better; no rule where it never does;
 * 4. first-try window: sigma² regressed on the player spread², 1/(k+1) and the size group, then the quantiles of the
 *    standardised residuals per WindowBucket;
 * 5. repeat window: the same on the stored repeat predictions with the player's repeat spread.
 */
final readonly class PredictionModelFitter
{
    public const array TAU_CANDIDATES = [0.25, 0.5, 0.75, 1.0, 1.5, 2.0, 3.0];

    /** Own first attempts below which the 500-scaled pace is used (ALWAYS = whenever the player has one) */
    public const array THIN_THRESHOLDS = [1, 10, 20, 50, self::ALWAYS];

    public const int ALWAYS = 1_000_000;

    private const int MINIMUM_TAU_SAMPLES = 500;
    private const int MINIMUM_ALPHA_PAIRS = 30;
    private const int MINIMUM_THIN_SAMPLES = 200;
    private const float THIN_MINIMUM_GAIN = 0.001;
    private const int MINIMUM_VARIANCE_SAMPLES = 2000;
    private const int MINIMUM_BUCKET_SAMPLES = 200;
    /**
     * A size group's own term needs this many first tries, like a calibration bucket: a variance estimated from n
     * residuals is off by ~sqrt(2/n) of itself (10 % at 200) - below that a group keeps the research's term instead of
     * a noisy one or the 0 the non-negative fit can push it to.
     */
    public const int MINIMUM_SIZE_TERM_SAMPLES = 200;
    private const float RESIDUAL_CLIP = 1.0;

    /**
     * @param float $from epoch seconds - attempts solved in [from, to) are fitted on
     */
    public function fit(PredictionEngine $engine, PriorLookup $priors, RepeatHistory $repeats, float $from, float $to, DateTimeImmutable $now): PredictionModelParameters
    {
        $defaults = PredictionModelParameters::defaults();
        $window = $this->window($engine->set, $from, $to);

        [$tau, $tauSamples] = $this->fitTau($engine, $priors, $window);
        $engine->computeDrift($priors, $tau);

        [$thinSizes, $thinSummary] = $this->fitThinSizes($engine, $priors, $window, $tau, $to);

        $withCentre = $defaults->with(tau: $tau, thinSizes: $thinSizes);

        $firstTry = $this->fitFirstTryWindows($engine, $priors, $window, $withCentre);
        $repeat = $this->fitRepeatWindows($repeats, $from, $to);

        return $withCentre->with(
            firstTryPlayerSpreadWeight: $firstTry['spread_weight'] ?? null,
            firstTryPuzzleWeight: $firstTry['puzzle_weight'] ?? null,
            firstTrySizeTerms: $firstTry['size_terms'] ?? null,
            repeatSpreadWeight: $repeat['spread_weight'] ?? null,
            repeatConstant: $repeat['constant'] ?? null,
            firstTryConsistencyEdges: $firstTry['edges'] ?? null,
            repeatConsistencyEdges: $repeat['edges'] ?? null,
            quantiles: [...$defaults->quantiles, ...($firstTry['quantiles'] ?? []), ...($repeat['quantiles'] ?? [])],
            fittedAt: $now,
            summary: [
                'window_from' => new DateTimeImmutable('@' . (int) $from)->format('Y-m-d'),
                'window_to' => new DateTimeImmutable('@' . (int) $to)->format('Y-m-d'),
                'tau_samples' => $tauSamples,
                'first_try_samples' => $firstTry['samples'],
                'repeat_samples' => $repeat['samples'],
                ...$thinSummary,
            ],
        );
    }

    /**
     * The attempts fitted on - without 0-second times: no measurement, and no log (the drift skips them too).
     *
     * @return list<int>
     */
    private function window(FirstAttemptSet $set, float $from, float $to): array
    {
        $window = [];

        foreach ($set->solved as $i => $solved) {
            if ($solved >= $from && $solved < $to && $set->seconds[$i] > 0) {
                $window[] = $i;
            }
        }

        return $window;
    }

    /**
     * @param list<int> $window
     * @return array{0: float, 1: int}
     */
    private function fitTau(PredictionEngine $engine, PriorLookup $priors, array $window): array
    {
        $samples = [];
        $set = $engine->set;

        foreach ($window as $i) {
            $k = $engine->k[$i];

            if ($k < 1 || $k >= TimePredictionCalculator::RATED_INDICES) {
                continue;
            }

            $row = $engine->timeline->directRowBefore((string) $set->player[$i], (int) $set->pieces[$i], (float) $set->solved[$i], (float) $set->tracked[$i]);

            if ($row === null || $row['baseline'] <= 0) {
                continue;
            }

            $prior = $engine->priorLog($i, $priors) ?? 0.0;
            $samples[] = [log($set->seconds[$i] / $row['baseline']), $engine->sumLog[$i], $k, $prior];
        }

        if (count($samples) < self::MINIMUM_TAU_SAMPLES) {
            return [PredictionModelParameters::defaults()->tau, count($samples)];
        }

        $best = null;

        foreach (self::TAU_CANDIDATES as $tau) {
            $error = 0.0;

            foreach ($samples as [$target, $sumLog, $k, $prior]) {
                $error += abs($target - ($sumLog + $tau * $prior) / ($k + $tau));
            }

            if ($best === null || $error < $best[1]) {
                $best = [$tau, $error];
            }
        }

        return [$best[0], count($samples)];
    }

    /**
     * @param list<int> $window
     * @return array{0: array<string, array{alpha: float, max_own_solves: int}>, 1: array<string, int|float|string>}
     */
    private function fitThinSizes(PredictionEngine $engine, PriorLookup $priors, array $window, float $tau, float $to): array
    {
        $set = $engine->set;
        $rules = [];
        $summary = [];

        // The size exponent from the players' paces at both sizes as of the end of the window
        $pairs = [];

        foreach ($set->pairs() as $pairKey => $range) {
            [$player, $pieces] = explode('|', $pairKey);
            $pieces = (int) $pieces;

            if ($pieces === 500) {
                continue;
            }

            $own = $engine->timeline->directRowBefore($player, $pieces, $to, $to);
            $five = $engine->timeline->directRowBefore($player, 500, $to, $to);

            if ($own !== null && $five !== null && $own['baseline'] > 0 && $five['baseline'] > 0) {
                $pairs[PiecesGroup::fromPiecesCount($pieces)->value][] = [log($own['baseline'] / $five['baseline']), log($pieces / 500)];
            }
        }

        $parameters = PredictionModelParameters::defaults()->with(tau: $tau);

        foreach ([PiecesGroup::UpTo300, PiecesGroup::Thousand, PiecesGroup::From1500, PiecesGroup::Odd] as $group) {
            $groupPairs = $pairs[$group->value] ?? [];

            if (count($groupPairs) < self::MINIMUM_ALPHA_PAIRS) {
                continue;
            }

            $numerator = 0.0;
            $denominator = 0.0;

            foreach ($groupPairs as [$logRatio, $logPieces]) {
                $numerator += $logRatio * $logPieces;
                $denominator += $logPieces ** 2;
            }

            if ($denominator <= 0.0) {
                continue;
            }

            $alpha = round($numerator / $denominator, 4);
            $summary['thin_alpha_' . $group->value] = $alpha;

            // Own baseline vs the 500-scaled pace on the same attempts (both known before it, k >= 1)
            $samples = [];

            foreach ($window as $i) {
                if (PiecesGroup::fromPiecesCount((int) $set->pieces[$i]) !== $group || $engine->k[$i] < 1) {
                    continue;
                }

                $inputs = $engine->inputsFor($i, $priors);

                if ($inputs->ownBaselineSeconds === null || $inputs->ownBaselineSeconds <= 0 || $inputs->pace500Seconds === null || $inputs->pace500Seconds <= 0) {
                    continue;
                }

                $prior = $inputs->kIndices < TimePredictionCalculator::RATED_INDICES ? $engine->priorLog($i, $priors) : null;
                $difficulty = TimePredictionCalculator::logDifficulty($inputs->kIndices, $inputs->sumLogIndices, $inputs->difficultyScore, $prior, $tau);

                if ($difficulty === null) {
                    continue;
                }

                $target = log((int) $set->seconds[$i]) - $difficulty[0];
                $own = TimePredictionCalculator::baselineFor($inputs, $parameters);
                assert($own !== null);
                $fromFive = log($inputs->pace500Seconds * ($inputs->piecesCount / 500) ** $alpha) + ($inputs->drift500 ?? 0.0);
                $samples[] = [
                    $inputs->ownBaselineDirect ? $inputs->ownQualifyingCount : 0,
                    abs($target - log($own[0]) - $own[1]),
                    abs($target - $fromFive),
                ];
            }

            $summary['thin_samples_' . $group->value] = count($samples);

            if (count($samples) < self::MINIMUM_THIN_SAMPLES) {
                continue;
            }

            $ownError = array_sum(array_column($samples, 1)) / count($samples);
            $summary['thin_own_mae_' . $group->value] = round($ownError, 4);
            $best = null;

            foreach (self::THIN_THRESHOLDS as $threshold) {
                $error = 0.0;

                foreach ($samples as [$ownCount, $own, $fromFive]) {
                    $error += $ownCount < $threshold ? $fromFive : $own;
                }

                $error /= count($samples);

                if ($best === null || $error < $best[1]) {
                    $best = [$threshold, $error];
                }
            }

            $summary['thin_best_mae_' . $group->value] = round($best[1], 4);

            if ($best[1] < $ownError - self::THIN_MINIMUM_GAIN) {
                $rules[$group->value] = ['alpha' => $alpha, 'max_own_solves' => $best[0]];
                $summary['thin_threshold_' . $group->value] = $best[0] === self::ALWAYS ? 'always' : $best[0];
            }
        }

        return [$rules, $summary];
    }

    /**
     * @param list<int> $window
     * @return array{spread_weight?: float, puzzle_weight?: float, size_terms?: array<string, float>, edges?: list<float>, quantiles?: array<string, array{0: float, 1: float, 2: float, 3: float}>, samples: int}
     */
    private function fitFirstTryWindows(PredictionEngine $engine, PriorLookup $priors, array $window, PredictionModelParameters $parameters): array
    {
        $groups = PiecesGroup::cases();
        // Column by column - a list of arrays per sample costs ~10× the memory
        $errors = [];
        $spreads = [];
        $ks = [];
        $sampleGroups = [];

        foreach ($window as $i) {
            $inputs = $engine->inputsFor($i, $priors);
            $prediction = $engine->calculator()->statistical($inputs, $parameters, allowPriorOnly: true);

            if ($prediction === null) {
                continue;
            }

            $baseline = TimePredictionCalculator::baselineFor($inputs, $parameters);
            assert($baseline !== null);
            $errors[] = log($engine->set->seconds[$i] / ($baseline[0] * exp((float) $prediction->logDifficulty + $baseline[1])));
            $spreads[] = $baseline[2] ?? PredictionModelParameters::GLOBAL_SPREAD;
            $ks[] = $inputs->kIndices;
            $sampleGroups[] = PiecesGroup::fromPiecesCount($inputs->piecesCount);
        }

        $count = count($errors);

        if ($count < self::MINIMUM_VARIANCE_SAMPLES) {
            return ['samples' => $count];
        }

        // Groups with too few first tries keep their default term: their samples still count for the two shared
        // weights, with that term taken off their target
        $perGroup = array_count_values(array_map(static fn (PiecesGroup $group): string => $group->value, $sampleGroups));
        $fittedGroups = array_values(array_filter(
            $groups,
            static fn (PiecesGroup $group): bool => ($perGroup[$group->value] ?? 0) >= self::MINIMUM_SIZE_TERM_SAMPLES,
        ));
        $defaultTerms = $parameters->firstTrySizeTerms;
        $normal = new NormalEquations(2 + count($fittedGroups));

        foreach ($errors as $sample => $error) {
            $row = [$spreads[$sample] ** 2, 1 / ($ks[$sample] + 1)];
            $sampleGroup = $sampleGroups[$sample];
            $fixedTerm = in_array($sampleGroup, $fittedGroups, true) ? 0.0 : ($defaultTerms[$sampleGroup->value] ?? 0.0);

            foreach ($fittedGroups as $group) {
                $row[] = $sampleGroup === $group ? 1.0 : 0.0;
            }

            $normal->add($row, min(abs($error), self::RESIDUAL_CLIP) ** 2 - $fixedTerm);
        }

        $coefficients = $normal->solveNonNegative();
        $sizeTerms = $defaultTerms;

        foreach ($fittedGroups as $index => $group) {
            $sizeTerms[$group->value] = round($coefficients[$index + 2], 6);
        }

        $fitted = $parameters->with(
            firstTryPlayerSpreadWeight: round($coefficients[0], 6),
            firstTryPuzzleWeight: round($coefficients[1], 6),
            firstTrySizeTerms: $sizeTerms,
        );

        $edges = self::quartileEdges($spreads);
        $byKey = [];

        foreach ($errors as $sample => $error) {
            $z = $error / $fitted->firstTrySigma($spreads[$sample], $ks[$sample], $sampleGroups[$sample]);

            foreach (WindowBucket::firstTry($spreads[$sample], $edges, $ks[$sample], $sampleGroups[$sample])->fallbackKeys() as $key) {
                $byKey[$key][] = $z;
            }
        }

        return [
            'spread_weight' => $fitted->firstTryPlayerSpreadWeight,
            'puzzle_weight' => $fitted->firstTryPuzzleWeight,
            'size_terms' => $sizeTerms,
            'edges' => $edges,
            'quantiles' => self::quantileTable($byKey),
            'samples' => $count,
        ];
    }

    /**
     * @return array{spread_weight?: float, constant?: float, edges?: list<float>, quantiles?: array<string, array{0: float, 1: float, 2: float, 3: float}>, samples: int}
     */
    private function fitRepeatWindows(RepeatHistory $repeats, float $from, float $to): array
    {
        $window = [];

        foreach ($repeats->solved as $i => $solved) {
            if ($solved >= $from && $solved < $to) {
                $window[] = $i;
            }
        }

        if (count($window) < self::MINIMUM_VARIANCE_SAMPLES) {
            return ['samples' => count($window)];
        }

        $normal = new NormalEquations(2);
        $errors = [];

        foreach ($window as $i) {
            $error = log($repeats->seconds[$i] / $repeats->predicted[$i]);
            $errors[$i] = $error;
            $normal->add([$repeats->spread[$i] ** 2, 1.0], min(abs($error), self::RESIDUAL_CLIP) ** 2);
        }

        [$spreadWeight, $constant] = $normal->solveNonNegative();

        $fitted = PredictionModelParameters::defaults()->with(
            repeatSpreadWeight: round($spreadWeight, 6),
            repeatConstant: round($constant, 6),
        );

        $edges = self::quartileEdges(array_map(static fn (int $i): float => $repeats->spread[$i], $window));
        $byKey = [];

        foreach ($errors as $i => $error) {
            $z = $error / $fitted->repeatSigma($repeats->spread[$i]);

            foreach (WindowBucket::repeat($repeats->spread[$i], $edges, $repeats->group[$i])->fallbackKeys() as $key) {
                $byKey[$key][] = $z;
            }
        }

        return [
            'spread_weight' => $fitted->repeatSpreadWeight,
            'constant' => $fitted->repeatConstant,
            'edges' => $edges,
            'quantiles' => self::quantileTable($byKey),
            'samples' => count($window),
        ];
    }

    /**
     * @param array<string, list<float>> $byKey
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function quantileTable(array $byKey): array
    {
        $table = [];

        foreach ($byKey as $key => $values) {
            if (count($values) < self::MINIMUM_BUCKET_SAMPLES) {
                continue;
            }

            sort($values);
            $table[$key] = [
                round(self::quantile($values, 0.10), 4),
                round(self::quantile($values, 0.25), 4),
                round(self::quantile($values, 0.75), 4),
                round(self::quantile($values, 0.90), 4),
            ];
        }

        return $table;
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    public static function quartileEdges(array $values): array
    {
        sort($values);

        return [
            round(self::quantile($values, 0.25), 4),
            round(self::quantile($values, 0.50), 4),
            round(self::quantile($values, 0.75), 4),
        ];
    }

    /**
     * Linear interpolation between the closest ranks (numpy's default).
     *
     * @param list<float> $sorted ascending, not empty
     */
    public static function quantile(array $sorted, float $q): float
    {
        $position = $q * (count($sorted) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $sorted[$lower] + ($position - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

    /**
     * Least squares without negative coefficients over plain rows (NormalEquations::solveNonNegative()).
     *
     * @param list<list<float>> $rows
     * @param list<float> $targets
     * @return list<float>
     */
    public static function nonNegativeLeastSquares(array $rows, array $targets): array
    {
        $normal = new NormalEquations(count($rows[0]));

        foreach ($rows as $index => $row) {
            $normal->add($row, $targets[$index]);
        }

        return $normal->solveNonNegative();
    }
}
