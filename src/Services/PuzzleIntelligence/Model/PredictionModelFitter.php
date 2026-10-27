<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\ImagePriorInput;
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
 * 5. repeat window: the same on the stored repeat predictions with the player's repeat spread;
 * 6. picture-only window: the scales of the estimate's own quantile offsets (TimePredictionCalculator::imageWindow())
 *    at which the windows of the predictions resting on the picture alone hold 50 / 80 % of the first tries at puzzles
 *    with 0-4 indices - one per window, per size group × image kind × k = 0 / 1-4 and coarser keys, recency-weighted
 *    (fitImageWindowScales()). The estimate's interval is built against well measured puzzles, a first try at a new one
 *    scatters more (gate record 2026-10-23: 42 / 71 % with the interval as is). Without estimates (or too few samples)
 *    no scales: the predictions keep the symmetric form with scale 1.0.
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
     * The picture-only window's scales weigh a first try by 0.5^(age ÷ this) - the window drifts between months
     * (500 pc flat, gate record 2026-10-27), chosen out of period on production rows: 30-60 days hold best, the
     * equal-weight year worst (docs/features/insights/image-estimate-gates.md, "The picture-only window")
     */
    public const float IMAGE_WINDOW_HALF_LIFE_DAYS = 45.0;
    /** A key's own scales need this many effective (recency-weighted, Kish) first tries - ~0.02 off at 50 % */
    public const int IMAGE_WINDOW_MINIMUM_EFFECTIVE = 600;
    private const float IMAGE_SCALE_MIN = 0.25;
    private const float IMAGE_SCALE_MAX = 10.0;
    /** An error no scale brings inside the window - finite, so the interpolating quantile() never meets INF - INF */
    private const float IMAGE_SCALE_NEVER = 1.0e9;

    /**
     * @param float $from epoch seconds - attempts solved in [from, to) are fitted on
     * @param array<string, ImagePriorInput> $imageEstimates the puzzles' picture-based estimates (step 6), by puzzle id
     */
    public function fit(PredictionEngine $engine, PriorLookup $priors, RepeatHistory $repeats, float $from, float $to, DateTimeImmutable $now, array $imageEstimates = []): PredictionModelParameters
    {
        $defaults = PredictionModelParameters::defaults();
        $window = $this->window($engine->set, $from, $to);

        [$tau, $tauSamples] = $this->fitTau($engine, $priors, $window);
        $engine->computeDrift($priors, $tau);

        [$thinSizes, $thinSummary] = $this->fitThinSizes($engine, $priors, $window, $tau, $to);

        $withCentre = $defaults->with(tau: $tau, thinSizes: $thinSizes);

        $firstTry = $this->fitFirstTryWindows($engine, $priors, $window, $withCentre);
        $repeat = $this->fitRepeatWindows($repeats, $from, $to);

        $withWindows = $withCentre->with(
            firstTryPlayerSpreadWeight: $firstTry['spread_weight'] ?? null,
            firstTryPuzzleWeight: $firstTry['puzzle_weight'] ?? null,
            firstTrySizeTerms: $firstTry['size_terms'] ?? null,
            firstTryConsistencyEdges: $firstTry['edges'] ?? null,
            quantiles: [...$defaults->quantiles, ...($firstTry['quantiles'] ?? [])],
        );
        $image = $this->fitImageWindowScales($engine, $priors, $window, $withWindows, $imageEstimates, $to);

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
                ...$image['summary'],
            ],
            imageWindowScales: $image['scales'] ?? null,
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
     * Step 6. Per first try at a puzzle with 0-4 indices and a picture estimate: its error against the prediction resting
     * on the picture alone, and the scale of the picture's part at which that error would just fall inside the 50 % and
     * the 80 % window (TimePredictionCalculator::imageWindow(): the end on the error's side reaches it once
     * (z × σ_player)² + s × e² ≥ error²). A window holds an attempt at every scale at least that, so each window's scale
     * is the weighted quantile (50 % / 80 %) of these thresholds - fitted separately: the two windows' shapes are the
     * estimate's own (its 50 % and its 80 % interval), one scale for both would trade one window's honesty for the
     * other's. Weighted by recency (half-life IMAGE_WINDOW_HALF_LIFE_DAYS before the end of the fit's window) and keyed
     * by every imageWindowKeys() key - size group × image kind × k = 0 / 1-4 and coarser - from
     * IMAGE_WINDOW_MINIMUM_EFFECTIVE effective first tries (Kish: (Σ w)² ÷ Σ w²); `all` from MINIMUM_VARIANCE_SAMPLES
     * first tries, else no scales at all (the predictions then keep the symmetric form).
     *
     * @param list<int> $window
     * @param array<string, ImagePriorInput> $imageEstimates
     * @return array{scales?: array<string, array{0: float, 1: float}>, summary: array<string, int|float|string>}
     */
    private function fitImageWindowScales(PredictionEngine $engine, PriorLookup $priors, array $window, PredictionModelParameters $parameters, array $imageEstimates, float $to): array
    {
        /** @var list<array{0: float, 1: float}> $needed */
        $needed = [];
        /** @var list<float> $weights */
        $weights = [];
        /** @var list<list<string>> $sampleKeys */
        $sampleKeys = [];
        /** @var array<string, list<int>> $byKey */
        $byKey = [];

        foreach ($window as $i) {
            $estimate = $imageEstimates[(string) $engine->set->puzzle[$i]] ?? null;
            $k = $engine->k[$i];

            if ($estimate === null || $k >= TimePredictionCalculator::RATED_INDICES) {
                continue;
            }

            $inputs = $engine->inputsFor($i, $priors)->withImageEstimate($estimate)->withoutSolves();
            // Only the centre is read - it rests on the picture alone, whatever the window's scales
            $prediction = $engine->calculator()->statistical($inputs, $parameters, allowPriorOnly: true);

            if ($prediction === null || $prediction->difficultySource !== DifficultySource::Image) {
                continue;
            }

            $baseline = TimePredictionCalculator::baselineFor($inputs, $parameters);
            assert($baseline !== null);
            $group = PiecesGroup::fromPiecesCount($inputs->piecesCount);
            $playerVariance = $parameters->firstTryPlayerVariance($baseline[2] ?? PredictionModelParameters::GLOBAL_SPREAD, $group);
            $offsets = $estimate->quantileOffsets();
            $z = PredictionModelParameters::NORMAL_QUANTILES;
            $error = log($engine->set->seconds[$i] / $prediction->predictedSeconds);
            $sample = count($needed);
            $needed[] = [
                self::neededImageScale($error, $playerVariance, [$z[1], $offsets[1]], [$z[2], $offsets[2]]),
                self::neededImageScale($error, $playerVariance, [$z[0], $offsets[0]], [$z[3], $offsets[3]]),
            ];
            $weights[] = 0.5 ** (max(0.0, $to - $engine->set->solved[$i]) / (self::IMAGE_WINDOW_HALF_LIFE_DAYS * 86400));
            $sampleKeys[] = PredictionModelParameters::imageWindowKeys($group, $estimate->kind, $k);

            foreach ($sampleKeys[$sample] as $key) {
                $byKey[$key][] = $sample;
            }
        }

        $count = count($needed);

        if ($count < self::MINIMUM_VARIANCE_SAMPLES) {
            return ['summary' => ['image_window_samples' => $count]];
        }

        ksort($byKey);
        $scales = [];
        $summary = ['image_window_samples' => $count, 'image_window_half_life_days' => self::IMAGE_WINDOW_HALF_LIFE_DAYS];

        foreach ($byKey as $key => $samples) {
            $keyWeights = array_map(static fn (int $sample): float => $weights[$sample], $samples);
            $squares = array_sum(array_map(static fn (float $weight): float => $weight ** 2, $keyWeights));
            $effective = $squares > 0.0 ? array_sum($keyWeights) ** 2 / $squares : 0.0;

            if ($key !== PredictionModelParameters::IMAGE_WINDOW_ALL && $effective < self::IMAGE_WINDOW_MINIMUM_EFFECTIVE) {
                continue;
            }

            $scales[$key] = [
                self::weightedQuantile(array_map(static fn (int $sample): float => $needed[$sample][0], $samples), $keyWeights, 0.50),
                self::weightedQuantile(array_map(static fn (int $sample): float => $needed[$sample][1], $samples), $keyWeights, 0.80),
            ];
            $summary['image_window_' . $key] = sprintf('%.4f / %.4f (%d)', $scales[$key][0], $scales[$key][1], (int) round($effective));
        }

        // What the fitted windows hold of the first tries they were fitted on, each at its most specific fitted key
        // (weighted, in-sample - for people; the honest number is the gate's out of period)
        $held = [0.0, 0.0];

        foreach ($needed as $sample => [$at50, $at80]) {
            $scale = $scales[PredictionModelParameters::IMAGE_WINDOW_ALL];

            foreach ($sampleKeys[$sample] as $key) {
                if (isset($scales[$key])) {
                    $scale = $scales[$key];

                    break;
                }
            }

            $held[0] += $at50 <= $scale[0] ? $weights[$sample] : 0.0;
            $held[1] += $at80 <= $scale[1] ? $weights[$sample] : 0.0;
        }

        $total = array_sum($weights);
        $summary['image_window50_hit'] = round($held[0] / $total, 4);
        $summary['image_window80_hit'] = round($held[1] / $total, 4);

        return ['scales' => $scales, 'summary' => $summary];
    }

    /**
     * The smallest scale s ≥ 0 at which the window end on the error's side - √((z × σ_player)² + s × e²) from the
     * centre - reaches the error: (error² − z² × σ_player²) ÷ e²; 0 when the player's part alone holds it, never when
     * the estimate has no interval on that side.
     *
     * @param array{0: float, 1: float} $lower the lower end's normal quantile and the estimate's offset (both ≤ 0)
     * @param array{0: float, 1: float} $upper the upper end's (both ≥ 0)
     */
    private static function neededImageScale(float $error, float $playerVariance, array $lower, array $upper): float
    {
        [$z, $offset] = $error >= 0.0 ? $upper : $lower;
        $excess = $error ** 2 - $z ** 2 * $playerVariance;

        if ($excess <= 0.0) {
            return 0.0;
        }

        return $offset !== 0.0 ? $excess / $offset ** 2 : self::IMAGE_SCALE_NEVER;
    }

    /**
     * The smallest needed scale at which the windows hold at least the target share of the weight (a weighted
     * quantile), within IMAGE_SCALE_MIN .. IMAGE_SCALE_MAX.
     *
     * @param list<float> $needed
     * @param list<float> $weights
     */
    private static function weightedQuantile(array $needed, array $weights, float $target): float
    {
        $order = array_keys($needed);
        usort($order, static fn (int $a, int $b): int => $needed[$a] <=> $needed[$b]);
        $total = array_sum($weights);
        $cumulative = 0.0;
        $scale = self::IMAGE_SCALE_MAX;

        foreach ($order as $i) {
            $cumulative += $weights[$i];

            if ($cumulative >= $target * $total - 1e-12) {
                $scale = $needed[$i];

                break;
            }
        }

        return round(min(max($scale, self::IMAGE_SCALE_MIN), self::IMAGE_SCALE_MAX), 4);
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
