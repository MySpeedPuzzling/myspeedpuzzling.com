<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\SizeCurve;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * Fits the candidate model (v4, CandidateModelParameters) on the attempts solved in a window, each as of its own moment
 * (PredictionEngine + CandidateEngine) - after the shown model's fit (its drift, spread and thin-size rules are shared):
 *
 * 1. index noise: the squared deviation of every index from the mean of the puzzle's other indices (puzzles with
 *    MINIMUM_NOISE_INDICES indices before the end of the window), non-negative least squares on [spread², 1 ÷ q, 1,
 *    indirect pace, the flags];
 * 2. the puzzle-weighted brand prior's pseudo-count (grid, first tries at puzzles without indices);
 * 3. a variance per prior type (grid, first tries at puzzles with 0-9 indices): the posterior's mean error is least;
 * 4. centre offsets: the median residual per key (CandidatePredictionCalculator::offsetKeys()), shrunk;
 * 5. first-try window: sigma² regressed on [spread², posterior variance, size groups, pace sources, dispersion]; the
 *    competition scale; standardised-residual quantiles per key;
 * 6. repeats: sigma² on [spread², 1, gap buckets], a median offset per gap bucket, quantiles per key with the gap.
 */
final readonly class CandidateModelFitter
{
    public const int MINIMUM_NOISE_INDICES = 10;
    public const array PRIOR_VARIANCE_GRID = [0.002, 0.003, 0.004, 0.005, 0.006, 0.008, 0.01, 0.0125, 0.015, 0.02, 0.025, 0.03, 0.04, 0.05, 0.07, 0.1, 0.15];
    public const array BRAND_PSEUDO_GRID = [1.0, 2.0, 3.0, 5.0, 10.0];
    public const int PRIOR_MAX_K = 9;

    private const int MINIMUM_SAMPLES = 500;
    private const int MINIMUM_KEY_SAMPLES = 200;
    private const int OFFSET_SHRINK = 200;
    private const int MINIMUM_SIZE_TERM_SAMPLES = 200;
    private const float RESIDUAL_CLIP = 1.0;
    private const float NOISE_CLIP = 1.0;
    private const int MINIMUM_UNBOXED_SAMPLES = 100;
    private const int UNBOXED_SHRINK = 50;

    public function __construct(
        private CandidatePredictionCalculator $calculator = new CandidatePredictionCalculator(),
        private SizeCurveFitter $sizeCurveFitter = new SizeCurveFitter(),
    ) {
    }

    /**
     * Per first try of the samples: its piece count, ln t - its posterior log difficulty, the solver's direct rows at
     * every size and their 1-4 first tries at the size (SizeCurveFitter).
     *
     * @param \Closure(string, int): array{0: null|float, 1: null|float} $priorsOf
     * @return iterable<array{0: int, 1: float, 2: list<array{0: int, 1: int, 2: float, 3: float, 4: int}>, 3: null|array{0: int, 1: float}, 4: int}>
     */
    private function sizeCurveInputs(CandidateSamples $samples, CandidateEngine $candidate, \Closure $priorsOf, CandidateModelParameters $parameters): iterable
    {
        $set = $candidate->engine->set;

        for ($s = 0, $count = $samples->count(); $s < $count; $s++) {
            $i = $samples->attempt($s);
            [$player, $pieces, $solved, $tracked] = [$set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i]];
            yield [
                $pieces,
                log($set->seconds[$i]) - $this->logDifficulty($samples, $s, $parameters)[0],
                $candidate->paceRowsAt($player, $solved, $tracked),
                $candidate->fewAt($player, $pieces, $solved, $tracked, self::logDifficultyAt($candidate, $solved, $tracked, $priorsOf, $parameters)),
                SizeCurveFitter::foldOf($player),
            ];
        }
    }

    /**
     * A puzzle's candidate log difficulty as known at a moment (the evidence before it, its prior) - the own first tries
     * of the size curve. One moment for every first try (the fit's / the evaluation's cut-off: no index after it is
     * read; the live path uses the current one), cached per puzzle in the engine.
     *
     * @param \Closure(string, int): array{0: null|float, 1: null|float} $priorsOf puzzle, pieces => [image estimate, brand prior] (priorsOf())
     * @return callable(string, int): ?float
     */
    public static function logDifficultyAt(CandidateEngine $candidate, float $solved, float $tracked, \Closure $priorsOf, CandidateModelParameters $parameters): callable
    {
        $solved = $candidate->fewMoment ?? $solved;
        $tracked = $candidate->fewMoment ?? $tracked;

        return static function (string $puzzle, int $pieces) use ($candidate, $solved, $tracked, $priorsOf, $parameters): float {
            if (!isset($candidate->fewDifficulty[$puzzle])) {
                [$imageLog, $brandLog] = $priorsOf($puzzle, $pieces);
                $candidate->fewDifficulty[$puzzle] = CandidatePredictionCalculator::puzzleLogDifficulty($candidate->evidenceAt($puzzle, $solved, $tracked, $parameters), $imageLog, $brandLog, $parameters);
            }

            return $candidate->fewDifficulty[$puzzle];
        };
    }

    /**
     * A puzzle's priors for the size curve's own first tries: [its image estimate, its candidate brand prior].
     *
     * @param array<string, ImagePriorInput> $imageEstimates
     * @return \Closure(string, int): array{0: null|float, 1: null|float}
     */
    public static function priorsOf(PriorLookup $priors, array $imageEstimates, CandidateModelParameters $parameters): \Closure
    {
        return static fn (string $puzzle, int $pieces): array => [
            ($imageEstimates[$puzzle] ?? null)?->logDifficulty,
            $priors->puzzleWeightedBrandPrior($puzzle, $pieces, $parameters->brandPseudoPuzzles),
        ];
    }

    /**
     * The whole fit in one call (the time-split evaluation): begin(), sample(), fitNoise(), addEvidence(), fitSizeCurve(),
     * finish() - the daily
     * fit (CandidateModelRecalculator) calls them one by one and releases what the next ones no longer read.
     *
     * @param RepeatHistory|\Closure(): RepeatHistory $repeats the repeats, or how to load them when their step comes
     * @param array<string, ImagePriorInput> $imageEstimates by puzzle id
     */
    public function fit(
        CandidateEngine $candidate,
        PriorLookup $priors,
        RepeatHistory|\Closure $repeats,
        float $from,
        float $to,
        DateTimeImmutable $now,
        array $imageEstimates,
        PredictionModelParameters $shown,
        CandidateFeatures $features,
        UnboxedFirstTries $unboxed = new UnboxedFirstTries(),
    ): CandidateModelParameters {
        $progress = $this->begin($candidate, $priors, $from, $to, $shown, $features);
        $this->sample($progress, $candidate, $priors, $imageEstimates, $unboxed);
        $this->fitNoise($progress, $candidate);
        $this->addEvidence($progress, $candidate);
        $this->fitSizeCurve($progress, $candidate);

        return $this->finish($progress, $repeats, $now);
    }

    /**
     * Step 2, the brand prior's pseudo-count - what the candidate's brand priors rest on.
     */
    public function begin(CandidateEngine $candidate, PriorLookup $priors, float $from, float $to, PredictionModelParameters $shown, CandidateFeatures $features): CandidateFitProgress
    {
        $progress = new CandidateFitProgress(
            CandidateModelParameters::defaults()->with(features: $features->toArray(), thinSizes: $shown->thinSizes),
            $features,
            $from,
            $to,
        );

        if ($features->on('puzzle_weighted_brand')) {
            [$pseudo, $pseudoSamples] = $this->fitBrandPseudo($candidate->engine, $priors, $this->window($candidate->engine->set, $from, $to), $progress->parameters);
            $progress->parameters = $progress->parameters->with(brandPseudoPuzzles: $pseudo);
            $progress->add(['brand_pseudo_samples' => $pseudoSamples]);
        }

        $progress->lap('brand_pseudo');

        return $progress;
    }

    /**
     * The window's first tries as the shown model sees them - each sample's target, prior, size group, pace source and
     * spread - and the unboxed first tries' inputs: the last step that reads the shown model's as-of difficulty, the
     * priors and the image estimates (PredictionEngine::releaseAsOfDifficulty() after it).
     *
     * @param array<string, ImagePriorInput> $imageEstimates by puzzle id
     */
    public function sample(CandidateFitProgress $progress, CandidateEngine $candidate, PriorLookup $priors, array $imageEstimates, UnboxedFirstTries $unboxed): void
    {
        $engine = $candidate->engine;
        [$from, $to, $features] = [$progress->from, $progress->to, $progress->features];
        [$progress->samples, $progress->paceless] = $this->samples($candidate, $priors, $this->window($engine->set, $from, $to), $imageEstimates, $progress->parameters);

        // The size curve's own first tries read their puzzles' priors after the lookups are released: two small maps
        if ($features->on('size_curve')) {
            $priorsOf = self::priorsOf($priors, $imageEstimates, $progress->parameters);
            $set = $engine->set;

            foreach ($set->puzzles() as $puzzle) {
                $first = $set->attemptsOfPuzzle($puzzle)[0] ?? null;

                if ($first !== null) {
                    [$imageLog, $brandLog] = $priorsOf($puzzle, $set->pieces[$first]);

                    if ($imageLog !== null) {
                        $progress->fewImageLog[$puzzle] = $imageLog;
                    }

                    if ($brandLog !== null) {
                        $progress->fewBrandLog[$puzzle] = $brandLog;
                    }
                }
            }
        }

        if ($features->on('unboxed')) {
            for ($u = 0, $count = $unboxed->count(); $u < $count; $u++) {
                if ($unboxed->solved[$u] >= $from && $unboxed->solved[$u] < $to) {
                    $puzzle = $unboxed->puzzle[$u];
                    $progress->unboxed[] = [
                        $engine->inputsAt($unboxed->player[$u], $puzzle, $unboxed->pieces[$u], $unboxed->solved[$u], $unboxed->tracked[$u], $priors)
                            ->withImageEstimate($imageEstimates[$puzzle] ?? null),
                        $unboxed->seconds[$u],
                        $unboxed->competition[$u],
                        [$unboxed->player[$u], $puzzle, $unboxed->solved[$u], $unboxed->tracked[$u], $priors->puzzleWeightedBrandPrior($puzzle, $unboxed->pieces[$u], $progress->parameters->brandPseudoPuzzles), $unboxed->pieces[$u]],
                    ];
                }
            }
        }

        $progress->lap('samples');
    }

    /**
     * Step 1, the index noise - what the puzzles' evidence rests on.
     */
    public function fitNoise(CandidateFitProgress $progress, CandidateEngine $candidate): void
    {
        [$noise, $noiseSamples] = $this->fitIndexNoise($candidate, $progress->to, $progress->features->on('drift_index'));
        $progress->parameters = $progress->parameters->with(indexNoise: $noise);
        $progress->add(['noise_samples' => $noiseSamples]);
        $progress->lap('noise');
    }

    /**
     * Every window first try's evidence as of its moment (CandidateEngine::compute()) into the samples and the unboxed
     * first tries' inputs: after this nothing of the fit reads the attempt set or the engines.
     */
    public function addEvidence(CandidateFitProgress $progress, CandidateEngine $candidate): void
    {
        $samples = $progress->samples ?? throw new \LogicException('begin() first');
        $candidate->compute($progress->parameters, $progress->from);
        $samples->useEvidenceOf($candidate);

        foreach ($progress->unboxed as $u => [$inputs, , , [$player, $puzzle, $solved, $tracked, $prior]]) {
            $progress->unboxed[$u][0] = $inputs->withCandidate($candidate->evidenceAt($puzzle, $solved, $tracked, $progress->parameters), $prior, $candidate->experienceAt($player, $solved, $tracked));
        }

        $progress->lap('evidence');
    }

    /**
     * The size curve (switch size_curve): the prior variances on the samples as they are, the curve on their posterior
     * difficulty (SizeCurveFitter), then every window first try paced once more - by the curve where it has a pace, else
     * as before (a first try without the shown model's pace joins, at the end, when the curve has one) - and the unboxed
     * first tries' curve inputs. Reads the attempt set and the drift states; the puzzles' priors from the maps sample()
     * kept - not the shown as-of difficulty, the prior lookup or the image estimates.
     */
    public function fitSizeCurve(CandidateFitProgress $progress, CandidateEngine $candidate): void
    {
        if ($progress->features->on('size_curve') === false) {
            $progress->paceless = [];

            return;
        }

        $samples = $progress->samples ?? throw new \LogicException('begin() first');
        $parameters = $progress->parameters;
        [$imageLogs, $brandLogs] = [$progress->fewImageLog, $progress->fewBrandLog];
        $priorsOf = static fn (string $puzzle, int $pieces): array => [$imageLogs[$puzzle] ?? null, $brandLogs[$puzzle] ?? null];
        [$variances, $varianceSummary] = $this->fitPriorVariances($samples, $progress->features->on('prior_types'));
        $parameters = $parameters->with(priorVariances: [...$parameters->priorVariances, ...$variances]);
        $progress->add($varianceSummary);

        // The difficulty of the own first tries' puzzles as of the end of the window, once per puzzle
        $candidate->fewAsOf($progress->to);

        try {
            $curve = $this->sizeCurveFitter->fit($this->sizeCurveInputs($samples, $candidate, $priorsOf, $parameters), $samples->count());
        } catch (NotIdentifiable $e) {
            // Not identifiable from this window: the curve the candidate has (its last fit's), else the review's
            $kept = $progress->previousSizeCurve ?? new SizeCurve();
            $curve = new SizeCurve($kept->knots, $kept->values, $kept->beta, $kept->tau2, $kept->cPace, $kept->qCap, $kept->sigma1Squared, [
                'size_curve_not_identifiable' => $e->getMessage(),
                'size_curve_kept' => $progress->previousSizeCurve !== null ? 'previous' : 'default',
            ]);
        }

        $parameters = $parameters->with(sizeCurve: $curve);
        $progress->parameters = $parameters;
        $progress->add($curve->summary);
        $progress->lap('size_curve');

        $this->pace($samples, $progress->paceless, $candidate, $priorsOf, $parameters);
        $progress->paceless = [];
        $progress->fewImageLog = $progress->fewBrandLog = [];
        unset($samples);

        foreach ($progress->unboxed as $u => [$inputs, , , [$player, , $solved, $tracked, , $pieces]]) {
            $progress->unboxed[$u][0] = $inputs->withCandidatePace(
                $candidate->paceRowsAt($player, $solved, $tracked),
                $candidate->fewAt($player, $pieces, $solved, $tracked, self::logDifficultyAt($candidate, $solved, $tracked, $priorsOf, $parameters)),
            );
        }

        $progress->lap('samples_with_curve');
    }

    /**
     * The samples paced by the fitted curve where it has a pace (in place - a second copy of the samples would be the
     * fit's peak), then the first tries without the shown model's pace that the curve paces, appended.
     *
     * @param list<array{0: int, 1: string, 2: float, 3: null|float, 4: int, 5: bool}> $paceless [attempt, prior type, prior, median, first tries, at an event]
     * @param \Closure(string, int): array{0: null|float, 1: null|float} $priorsOf
     */
    private function pace(CandidateSamples $samples, array $paceless, CandidateEngine $candidate, \Closure $priorsOf, CandidateModelParameters $parameters): void
    {
        $curve = $parameters->sizeCurve ?? throw new \LogicException('No size curve');
        $set = $candidate->engine->set;
        $pooledOf = static function (int $i) use ($curve, $set, $candidate, $priorsOf, $parameters): null|array {
            [$player, $pieces, $solved, $tracked] = [$set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i]];

            return $curve->pace(
                $pieces,
                $candidate->paceRowsAt($player, $solved, $tracked),
                $candidate->fewAt($player, $pieces, $solved, $tracked, self::logDifficultyAt($candidate, $solved, $tracked, $priorsOf, $parameters)),
            );
        };

        for ($s = 0, $count = $samples->count(); $s < $count; $s++) {
            $i = $samples->attempt($s);
            $pooled = $pooledOf($i);

            if ($pooled !== null) {
                $samples->repace($s, log($set->seconds[$i]) - $pooled[0], $pooled[2], $pooled[3], $pooled[1]);
            }
        }

        $added = [];

        foreach ($paceless as [$i, $priorType, $priorLog, $median, $firstTries, $competition]) {
            $pooled = $pooledOf($i);

            if ($pooled !== null) {
                $added[] = [$i, $priorType, $priorLog, $median, $firstTries, $competition, $pooled];
            }
        }

        $samples->grow(count($added));

        foreach ($added as [$i, $priorType, $priorLog, $median, $firstTries, $competition, $pooled]) {
            $samples->add(
                attempt: $i,
                target: log($set->seconds[$i]) - $pooled[0],
                priorType: $priorType,
                priorLog: $priorLog,
                group: PiecesGroup::fromPiecesCount($set->pieces[$i]),
                paceSource: $pooled[2],
                spread: $pooled[3],
                median: $median,
                firstTries: $firstTries,
                competition: $competition,
                pieces: $set->pieces[$i],
                paceVariance: $pooled[1],
                slot: $candidate->slotOf($i) ?? -1,
            );
        }
    }

    /**
     * Steps 3-6 on what the earlier steps kept: the prior variances, the centre offsets, the first-try window, the
     * unboxed offset and scale, the repeats.
     *
     * @param RepeatHistory|\Closure(): RepeatHistory $repeats the repeats, or how to load them when their step comes
     */
    public function finish(CandidateFitProgress $progress, RepeatHistory|\Closure $repeats, DateTimeImmutable $now): CandidateModelParameters
    {
        $samples = $progress->samples ?? throw new \LogicException('begin() first');
        $features = $progress->features;
        $parameters = $progress->parameters;

        [$variances, $varianceSummary] = $this->fitPriorVariances($samples, $features->on('prior_types'));
        $parameters = $parameters->with(priorVariances: [...$parameters->priorVariances, ...$variances]);
        $progress->add($varianceSummary);

        if ($features->on('centre_offsets')) {
            $parameters = $parameters->with(centreOffsets: $this->fitCentreOffsets($samples, $parameters));
        }

        $progress->lap('priors_offsets');
        [$parameters, $windowSummary] = $this->fitFirstTryWindow($samples, $parameters, $features);
        $progress->add($windowSummary);
        $progress->samples = null;
        unset($samples);
        $progress->lap('window');

        if ($features->on('unboxed')) {
            [$parameters, $unboxedSummary] = $this->fitUnboxed(array_map(static fn (array $unboxed): array => [$unboxed[0], $unboxed[1], $unboxed[2]], $progress->unboxed), $parameters);
            $progress->add($unboxedSummary);
            $progress->unboxed = [];
            $progress->lap('unboxed');
        }

        [$parameters, $repeatSummary] = $this->fitRepeats($repeats instanceof RepeatHistory ? $repeats : $repeats(), $progress->from, $progress->to, $parameters, $features->on('repeat_gap'));
        $progress->add($repeatSummary);
        $progress->lap('repeats');
        $progress->add(['memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576)]);

        return $parameters->with(fittedAt: $now, summary: $progress->summary);
    }

    /**
     * The window's attempts with a time.
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
     * The inputs of the attempt's candidate prediction as of its moment: the shown model's inputs with the candidate's
     * evidence, its brand prior and (when it has one) the puzzle's image estimate.
     *
     * @param array<string, ImagePriorInput> $imageEstimates
     */
    public static function inputsFor(CandidateEngine $candidate, int $i, PriorLookup $priors, array $imageEstimates, CandidateModelParameters $parameters): StatisticalPredictionInputs
    {
        $set = $candidate->engine->set;
        $puzzle = $set->puzzle[$i];

        $inputs = $candidate->engine->inputsFor($i, $priors)
            ->withImageEstimate($imageEstimates[$puzzle] ?? null)
            ->withCandidate($candidate->evidenceFor($i), $priors->puzzleWeightedBrandPrior($puzzle, $set->pieces[$i], $parameters->brandPseudoPuzzles), $candidate->experienceOf($i));

        return self::withPaceInputs($inputs, $candidate, $set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i], $priors, $imageEstimates, $parameters);
    }

    /**
     * The size curve's inputs when the fit has a curve (else none - nothing reads them).
     *
     * @param array<string, ImagePriorInput> $imageEstimates
     */
    private static function withPaceInputs(StatisticalPredictionInputs $inputs, CandidateEngine $candidate, string $player, int $pieces, float $solved, float $tracked, PriorLookup $priors, array $imageEstimates, CandidateModelParameters $parameters): StatisticalPredictionInputs
    {
        if ($parameters->sizeCurve === null || $parameters->features()->on('size_curve') === false) {
            return $inputs;
        }

        return $inputs->withCandidatePace(
            $candidate->paceRowsAt($player, $solved, $tracked),
            $candidate->fewAt($player, $pieces, $solved, $tracked, self::logDifficultyAt($candidate, $solved, $tracked, self::priorsOf($priors, $imageEstimates, $parameters), $parameters)),
        );
    }

    /**
     * The inputs of an unboxed first try's candidate prediction as of its moment (it is not in the qualifying set).
     *
     * @param array<string, ImagePriorInput> $imageEstimates
     */
    public static function unboxedInputs(CandidateEngine $candidate, UnboxedFirstTries $unboxed, int $u, PriorLookup $priors, array $imageEstimates, CandidateModelParameters $parameters): StatisticalPredictionInputs
    {
        $puzzle = $unboxed->puzzle[$u];

        $inputs = $candidate->engine->inputsAt($unboxed->player[$u], $puzzle, $unboxed->pieces[$u], $unboxed->solved[$u], $unboxed->tracked[$u], $priors)
            ->withImageEstimate($imageEstimates[$puzzle] ?? null)
            ->withCandidate(
                $candidate->evidenceAt($puzzle, $unboxed->solved[$u], $unboxed->tracked[$u], $parameters),
                $priors->puzzleWeightedBrandPrior($puzzle, $unboxed->pieces[$u], $parameters->brandPseudoPuzzles),
                $candidate->experienceAt($unboxed->player[$u], $unboxed->solved[$u], $unboxed->tracked[$u]),
            );

        return self::withPaceInputs($inputs, $candidate, $unboxed->player[$u], $unboxed->pieces[$u], $unboxed->solved[$u], $unboxed->tracked[$u], $priors, $imageEstimates, $parameters);
    }

    /**
     * The unboxed offset (median residual against the boxed prediction, shrunk) and window scale (how much of the
     * fitted variance unboxed first tries use around the shifted centre).
     *
     * @param list<array{0: StatisticalPredictionInputs, 1: int, 2: bool}> $unboxed the window's, from begin() and addEvidence()
     * @return array{0: CandidateModelParameters, 1: array<string, int|float|string>}
     */
    private function fitUnboxed(array $unboxed, CandidateModelParameters $parameters): array
    {
        $residuals = [];
        $boxedPredictions = [];

        foreach ($unboxed as [$inputs, $seconds, $competition]) {
            $boxed = $this->calculator->firstTry($inputs, $parameters, new SolveConditions(competition: $competition));

            if ($boxed === null) {
                continue;
            }

            $residuals[] = max(-self::RESIDUAL_CLIP, min(self::RESIDUAL_CLIP, log($seconds / $boxed->predictedSeconds)));
            $boxedPredictions[] = $boxed;
        }

        $samples = count($residuals);

        if ($samples < self::MINIMUM_UNBOXED_SAMPLES) {
            return [$parameters, ['unboxed_samples' => $samples]];
        }

        $sorted = $residuals;
        sort($sorted);
        $offset = round(PredictionModelFitter::quantile($sorted, 0.5) * $samples / ($samples + self::UNBOXED_SHRINK), 5);

        // The scale: the share of unboxed first tries inside the boxed 50 / 80 % windows shifted by the offset, brought
        // to 50 / 80 % by widening sigma - the smallest scale (grid) whose hits are closest to both
        $best = null;

        foreach ([1.0, 1.25, 1.5, 1.75, 2.0, 2.5, 3.0, 4.0] as $scale) {
            $hit50 = 0;
            $hit80 = 0;

            foreach ($boxedPredictions as $index => $boxed) {
                // The window bounds in log units around the centre scale with sigma, so with sqrt(scale)
                $centre = $boxed->predictedSeconds;
                $actual = $residuals[$index] - $offset;
                $width = sqrt($scale);
                $low50 = log($boxed->rangeLowSeconds / $centre) * $width;
                $high50 = log($boxed->rangeHighSeconds / $centre) * $width;
                $low80 = log((int) $boxed->range80LowSeconds / $centre) * $width;
                $high80 = log((int) $boxed->range80HighSeconds / $centre) * $width;
                $hit50 += $actual >= $low50 && $actual <= $high50 ? 1 : 0;
                $hit80 += $actual >= $low80 && $actual <= $high80 ? 1 : 0;
            }

            $miss = abs($hit50 / $samples - 0.5) + abs($hit80 / $samples - 0.8);

            if ($best === null || $miss < $best[1] - 1e-9) {
                $best = [$scale, $miss, $hit50 / $samples, $hit80 / $samples];
            }
        }

        return [
            $parameters->with(unboxedOffset: $offset, contextScales: [...$parameters->contextScales, 'unboxed' => $best[0]]),
            ['unboxed_samples' => $samples, 'unboxed_offset' => $offset, 'unboxed_scale' => $best[0], 'unboxed_hit50' => round($best[2], 3), 'unboxed_hit80' => round($best[3], 3)],
        ];
    }

    public static function conditionsFor(CandidateEngine $candidate, int $i): SolveConditions
    {
        return new SolveConditions(competition: ($candidate->flags->of($i) & AttemptFlags::COMPETITION) !== 0);
    }

    /**
     * @return array{0: array<string, float>, 1: int}
     */
    private function fitIndexNoise(CandidateEngine $candidate, float $to, bool $driftAdjusted): array
    {
        $set = $candidate->engine->set;
        $normal = new NormalEquations(7);
        $samples = 0;
        $flatSum = 0.0;

        foreach ($set->puzzles() as $puzzle) {
            $rows = [];

            foreach ($set->attemptsOfPuzzle($puzzle) as $i) {
                if ($set->solved[$i] >= $to) {
                    break;
                }

                $inputs = $candidate->noiseInputs($i, $driftAdjusted);

                if ($inputs !== null) {
                    $rows[] = $inputs;
                }
            }

            $count = count($rows);

            if ($count < self::MINIMUM_NOISE_INDICES) {
                continue;
            }

            $sum = array_sum(array_column($rows, 0));

            foreach ($rows as [$log, $spread2, $inverseQ, $indirect, $flags]) {
                $deviation = min(abs($log - ($sum - $log) / ($count - 1)), self::NOISE_CLIP);
                $normal->add([
                    $spread2,
                    $inverseQ,
                    1.0,
                    $indirect ? 1.0 : 0.0,
                    ($flags & AttemptFlags::UNFLAGGED) !== 0 ? 1.0 : 0.0,
                    ($flags & AttemptFlags::AFTER_GROUP) !== 0 ? 1.0 : 0.0,
                    ($flags & AttemptFlags::COMMENTED) !== 0 ? 1.0 : 0.0,
                ], $deviation ** 2);
                $flatSum += $deviation ** 2;
                $samples++;
            }
        }

        if ($samples < self::MINIMUM_SAMPLES) {
            return [CandidateModelParameters::defaults()->indexNoise, $samples];
        }

        $coefficients = $normal->solveNonNegative();

        return [[
            'spread2' => round($coefficients[0], 6),
            'inv_q' => round($coefficients[1], 6),
            'constant' => round($coefficients[2], 6),
            'indirect' => round($coefficients[3], 6),
            'unflagged' => round($coefficients[4], 6),
            'after_group' => round($coefficients[5], 6),
            'commented' => round($coefficients[6], 6),
            'flat' => round($flatSum / $samples, 6),
        ], $samples];
    }

    /**
     * @param list<int> $window
     * @return array{0: float, 1: int}
     */
    private function fitBrandPseudo(PredictionEngine $engine, PriorLookup $priors, array $window, CandidateModelParameters $parameters): array
    {
        $samples = [];
        $set = $engine->set;

        foreach ($window as $i) {
            if ($engine->k[$i] !== 0) {
                continue;
            }

            $inputs = $engine->inputsFor($i, $priors);
            $baseline = TimePredictionCalculator::baselineFor($inputs, $parameters->paceParameters);

            if ($baseline === null || $baseline[0] <= 0) {
                continue;
            }

            $samples[] = [$i, log($set->seconds[$i] / $baseline[0]) - $baseline[1]];
        }

        $best = null;

        foreach (self::BRAND_PSEUDO_GRID as $pseudo) {
            $error = 0.0;
            $count = 0;

            foreach ($samples as [$i, $target]) {
                $prior = $priors->puzzleWeightedBrandPrior($set->puzzle[$i], $set->pieces[$i], $pseudo);

                if ($prior !== null) {
                    $error += abs($target - $prior);
                    $count++;
                }
            }

            if ($count >= self::MINIMUM_SAMPLES && ($best === null || $error / $count < $best[1])) {
                $best = [$pseudo, $error / $count];
            }
        }

        return [$best[0] ?? $parameters->brandPseudoPuzzles, count($samples)];
    }

    /**
     * Every first try of the window the candidate predicts, column by column: its target (ln seconds - ln pace - drift),
     * the prior and what the window keys need - its evidence is added by addEvidence(); and the first tries without a
     * pace of the shown rules, which the size curve may pace (fitSizeCurve()).
     *
     * @param list<int> $window
     * @param array<string, ImagePriorInput> $imageEstimates
     * @return array{0: CandidateSamples, 1: list<array{0: int, 1: string, 2: float, 3: null|float, 4: int, 5: bool}>}
     */
    private function samples(CandidateEngine $candidate, PriorLookup $priors, array $window, array $imageEstimates, CandidateModelParameters $parameters): array
    {
        $features = $parameters->features();
        $samples = new CandidateSamples(count($window));
        $paceless = [];
        $engine = $candidate->engine;
        $set = $engine->set;

        foreach ($window as $i) {
            // The shown model's inputs with the candidate's brand prior - its evidence comes later (addEvidence())
            $puzzle = $set->puzzle[$i];
            $inputs = $engine->inputsFor($i, $priors)
                ->withImageEstimate($imageEstimates[$puzzle] ?? null)
                ->withCandidate(null, $priors->puzzleWeightedBrandPrior($puzzle, $set->pieces[$i], $parameters->brandPseudoPuzzles));
            $pace = CandidatePredictionCalculator::pace($inputs, $parameters);
            [$type, $prior] = $this->calculator->prior($inputs, $features->on('image_prior'), $features->on('puzzle_weighted_brand'));
            $score = $engine->score[$i] ?? null;
            $median = $engine->k[$i] >= TimePredictionCalculator::RATED_INDICES && $score !== null && $score > 0.0 ? log($score) : null;

            if ($pace === null) {
                // The size curve may pace it from the player's other sizes (fitSizeCurve())
                if ($features->on('size_curve')) {
                    $paceless[] = [$i, $type, $prior ?? 0.0, $median, $candidate->experienceOf($i), ($candidate->flags->of($i) & AttemptFlags::COMPETITION) !== 0];
                }

                continue;
            }

            $samples->add(
                attempt: $i,
                target: log($set->seconds[$i]) - $pace[0],
                priorType: $type,
                priorLog: $prior ?? 0.0,
                group: PiecesGroup::fromPiecesCount($inputs->piecesCount),
                paceSource: $pace[3],
                spread: $pace[2],
                median: $median,
                firstTries: $candidate->experienceOf($i),
                competition: ($candidate->flags->of($i) & AttemptFlags::COMPETITION) !== 0,
                pieces: $set->pieces[$i],
                paceVariance: $pace[1],
            );
        }

        $samples->finish();

        return [$samples, $paceless];
    }

    /**
     * @return array{0: array<string, float>, 1: array<string, int|float|string>}
     */
    private function fitPriorVariances(CandidateSamples $samples, bool $perType): array
    {
        $byType = [];

        for ($s = 0, $count = $samples->count(); $s < $count; $s++) {
            if ($samples->k($s) > self::PRIOR_MAX_K) {
                continue;
            }

            $byType[$perType ? $samples->priorType($s) : 'pooled'][] = $s;
        }

        $variances = [];
        $summary = [];

        foreach ($byType as $type => $members) {
            $summary['prior_samples_' . $type] = count($members);

            if (count($members) < self::MINIMUM_SAMPLES) {
                continue;
            }

            $best = null;

            foreach (self::PRIOR_VARIANCE_GRID as $variance) {
                $error = 0.0;

                foreach ($members as $s) {
                    $error += abs($samples->target($s) - $samples->evidence($s)->posterior($samples->priorLog($s), $variance)[0]);
                }

                if ($best === null || $error < $best[1]) {
                    $best = [$variance, $error];
                }
            }

            $variances[$type] = $best[0];
            $summary['prior_variance_' . $type] = $best[0];
            $summary['prior_mae_' . $type] = round($best[1] / count($members), 4);
        }

        return [$variances, $summary];
    }

    /**
     * The median residual per key, less the window's overall median (a level the whole window shares is a period
     * effect - measured: it did not carry over to the next months, the structure did), shrunk; then the same for the
     * player's experience on what the keys leave.
     *
     * @return array<string, float>
     */
    private function fitCentreOffsets(CandidateSamples $samples, CandidateModelParameters $parameters): array
    {
        $residuals = [];

        for ($s = 0, $count = $samples->count(); $s < $count; $s++) {
            $residuals[$s] = max(-self::RESIDUAL_CLIP, min(self::RESIDUAL_CLIP, $samples->target($s) - $this->logDifficulty($samples, $s, $parameters)[0]));
        }

        if ($residuals === []) {
            return [];
        }

        $level = self::median($residuals);
        $offsets = [];
        $keysOf = static fn (int $s): array => CandidatePredictionCalculator::offsetKeys($samples->group($s), $samples->paceSource($s)->value, WindowBucket::kBucket($samples->k($s)), $samples->priorType($s));

        foreach (self::perLevel(count($residuals), $keysOf, static fn (int $s): float => $residuals[$s]) as $byKey) {
            $offsets = [...$offsets, ...self::shrunkMedians($byKey, $level)];
        }

        $withOffsets = $parameters->with(centreOffsets: $offsets);
        $byExperience = [];

        foreach ($residuals as $s => $residual) {
            $bucket = CandidateModelParameters::experienceBucket($samples->firstTries($s));

            if ($bucket !== null) {
                $keys = CandidatePredictionCalculator::offsetKeys($samples->group($s), $samples->paceSource($s)->value, WindowBucket::kBucket($samples->k($s)), $samples->priorType($s));
                $byExperience['experience|' . $bucket][] = $residual - $withOffsets->centreOffset($keys);
            }
        }

        return [...$offsets, ...self::shrunkMedians($byExperience, $level)];
    }

    /**
     * The samples' values per key, one key level (its number of "|") at a time - every key of a sample is a fallback
     * level, so collecting all of them at once held the whole sample several times over (the fit runs in 512 MB).
     *
     * @param callable(int): list<string> $keysOf
     * @param callable(int): float $valueOf
     * @return iterable<array<string, list<float>>>
     */
    private static function perLevel(int $count, callable $keysOf, callable $valueOf): iterable
    {
        for ($level = 0; $level <= 4; $level++) {
            $byKey = [];

            for ($s = 0; $s < $count; $s++) {
                foreach ($keysOf($s) as $key) {
                    if (substr_count($key, '|') === $level) {
                        $byKey[$key][] = $valueOf($s);
                    }
                }
            }

            yield $byKey;
        }
    }

    /**
     * @param array<string, list<float>> $byKey
     * @return array<string, float>
     */
    private static function shrunkMedians(array $byKey, float $level): array
    {
        $offsets = [];
        ksort($byKey);

        foreach ($byKey as $key => $values) {
            $count = count($values);

            if ($count >= self::MINIMUM_KEY_SAMPLES) {
                $offsets[$key] = round((self::median($values) - $level) * $count / ($count + self::OFFSET_SHRINK), 5);
            }
        }

        return $offsets;
    }

    /**
     * @param array<int, float> $values
     */
    private static function median(array $values): float
    {
        sort($values);

        return PredictionModelFitter::quantile($values, 0.5);
    }

    /**
     * @return array{0: CandidateModelParameters, 1: array<string, int|float|string>}
     */
    private function fitFirstTryWindow(CandidateSamples $samples, CandidateModelParameters $parameters, CandidateFeatures $features): array
    {
        $count = $samples->count();

        if ($count < self::MINIMUM_SAMPLES) {
            return [$parameters, ['window_samples' => $count]];
        }

        $errors = [];
        $posteriorVariances = [];
        $dispersions = [];

        for ($s = 0, $count = $samples->count(); $s < $count; $s++) {
            [$logDifficulty, $posteriorVariance] = $this->logDifficulty($samples, $s, $parameters);
            $offset = $features->on('centre_offsets')
                ? $parameters->centreOffset(CandidatePredictionCalculator::offsetKeys($samples->group($s), $samples->paceSource($s)->value, WindowBucket::kBucket($samples->k($s)), $samples->priorType($s)))
                    + $parameters->experienceOffset($samples->firstTries($s))
                : 0.0;
            $errors[$s] = $samples->target($s) - $logDifficulty - $offset;
            $posteriorVariances[$s] = $posteriorVariance;
            $dispersions[$s] = $features->on('dispersion') ? $samples->evidence($s)->dispersion($parameters->dispersionShrink) : 0.0;
        }

        $perGroup = [];

        for ($s = 0; $s < $count; $s++) {
            $group = $samples->group($s);
            $perGroup[$group->value] = ($perGroup[$group->value] ?? 0) + 1;
        }
        $groups = array_values(array_filter(PiecesGroup::cases(), static fn (PiecesGroup $group): bool => ($perGroup[$group->value] ?? 0) >= self::MINIMUM_SIZE_TERM_SAMPLES));
        $curve = $parameters->sizeCurve !== null && $features->on('size_curve');
        $paceSources = $features->on('pace_terms') ? [PaceSource::Interpolated, PaceSource::Scaled500] : [];

        if ($curve) {
            $paceSources = [...$paceSources, PaceSource::Pooled, PaceSource::PooledFew, PaceSource::PooledOwn];
        }

        // New players scatter more (their pace rests on few first tries) - a term of their own, with the offsets
        $experiences = $features->on('centre_offsets') ? ['lt10', 'lt30'] : [];
        $columns = 2 + count($groups) + count($paceSources) + count($experiences) + ($features->on('dispersion') ? 1 : 0) + ($curve ? 1 : 0);
        $normal = new NormalEquations($columns);

        foreach ($errors as $s => $error) {
            $row = [$samples->spread($s) ** 2, $posteriorVariances[$s]];

            foreach ($groups as $group) {
                $row[] = $samples->group($s) === $group ? 1.0 : 0.0;
            }

            foreach ($paceSources as $paceSource) {
                $row[] = $samples->paceSource($s) === $paceSource ? 1.0 : 0.0;
            }

            foreach ($experiences as $experience) {
                $row[] = CandidateModelParameters::experienceBucket($samples->firstTries($s)) === $experience ? 1.0 : 0.0;
            }

            if ($features->on('dispersion')) {
                $row[] = $dispersions[$s];
            }

            if ($curve) {
                $row[] = $samples->paceVariance($s);
            }

            $normal->add($row, min(abs($error), self::RESIDUAL_CLIP) ** 2);
        }

        $coefficients = $normal->solveNonNegative();
        $sizeTerms = $parameters->sizeTerms;
        $column = 2;

        foreach ($groups as $group) {
            $sizeTerms[$group->value] = round($coefficients[$column++], 6);
        }

        $paceTerms = [];

        foreach ($paceSources as $paceSource) {
            $paceTerms[$paceSource->value] = round($coefficients[$column++], 6);
        }

        foreach ($experiences as $experience) {
            $paceTerms['experience|' . $experience] = round($coefficients[$column++], 6);
        }

        $spreads = $samples->spreads();
        $parameters = $parameters->with(
            spreadWeight: round($coefficients[0], 6),
            posteriorWeight: round($coefficients[1], 6),
            sizeTerms: $sizeTerms,
            paceTerms: $paceTerms,
            dispersionWeight: $features->on('dispersion') ? round($coefficients[$column], 6) : 0.0,
            consistencyEdges: PredictionModelFitter::quartileEdges($spreads),
            paceVarianceWeight: $curve ? round($coefficients[$columns - 1], 6) : 0.0,
        );

        $summary = ['window_samples' => $count];

        // The competition scale: how much of the fitted variance an event's first tries use
        if ($features->on('competition')) {
            $ratio = [0.0, 0.0, 0];

            foreach ($errors as $s => $error) {
                if ($samples->competition($s)) {
                    $ratio[0] += min(abs($error), self::RESIDUAL_CLIP) ** 2;
                    $ratio[1] += $parameters->firstTrySigma($samples->spread($s), $posteriorVariances[$s], $samples->group($s), $samples->paceSource($s), $dispersions[$s], 1.0, $samples->firstTries($s), $samples->paceVariance($s)) ** 2;
                    $ratio[2]++;
                }
            }

            $summary['competition_samples'] = $ratio[2];

            if ($ratio[2] >= self::MINIMUM_KEY_SAMPLES && $ratio[1] > 0.0) {
                $parameters = $parameters->with(contextScales: [...$parameters->contextScales, 'competition' => round($ratio[0] / $ratio[1], 4)]);
            }
        }

        $standardised = [];

        foreach ($errors as $s => $error) {
            $competition = $features->on('competition') && $samples->competition($s);
            $standardised[$s] = $error / $parameters->firstTrySigma($samples->spread($s), $posteriorVariances[$s], $samples->group($s), $samples->paceSource($s), $dispersions[$s], $parameters->contextScale($competition, false), $features->on('centre_offsets') ? $samples->firstTries($s) : null, $samples->paceVariance($s));
        }

        unset($errors, $posteriorVariances, $dispersions);
        $keysOf = static fn (int $s): array => CandidatePredictionCalculator::firstTryKeys(
            WindowBucket::quartile($samples->spread($s), $parameters->consistencyEdges),
            WindowBucket::kBucket($samples->k($s)),
            $samples->group($s),
            $features->on('source_quantiles') ? $samples->priorType($s) : null,
            CandidatePredictionCalculator::paceKey($samples->paceSource($s), $samples->pieces($s)),
        );
        $quantiles = $parameters->quantiles;

        foreach (self::perLevel($count, $keysOf, static fn (int $s): float => $standardised[$s]) as $byKey) {
            $quantiles = [...$quantiles, ...self::quantileTable($byKey)];
        }

        return [$parameters->with(quantiles: $quantiles), $summary];
    }

    /**
     * @return array{0: CandidateModelParameters, 1: array<string, int|float|string>}
     */
    private function fitRepeats(RepeatHistory $repeats, float $from, float $to, CandidateModelParameters $parameters, bool $withGap): array
    {
        $window = [];

        foreach ($repeats->solved as $i => $solved) {
            if ($solved >= $from && $solved < $to) {
                $window[] = $i;
            }
        }

        if (count($window) < self::MINIMUM_SAMPLES) {
            return [$parameters, ['repeat_samples' => count($window)]];
        }

        $gaps = $withGap ? ['1_3m', '3_12m', 'gt12m'] : [];
        $gapOf = static fn (int $i): string => $withGap ? TimePredictionCalculator::classifyGap($repeats->gapDays[$i]) : 'any';

        // The gap's offset first (median residual, shrunk), then the variance around the shifted centre
        $offsets = [];

        if ($withGap) {
            $byGap = [];

            foreach ($window as $i) {
                $byGap[$gapOf($i)][] = max(-self::RESIDUAL_CLIP, min(self::RESIDUAL_CLIP, log($repeats->seconds[$i] / $repeats->predicted[$i])));
            }

            foreach ($byGap as $gap => $residuals) {
                $count = count($residuals);

                if ($count >= self::MINIMUM_KEY_SAMPLES) {
                    sort($residuals);
                    $offsets[$gap] = round(PredictionModelFitter::quantile($residuals, 0.5) * $count / ($count + self::OFFSET_SHRINK), 5);
                }
            }
        }

        $normal = new NormalEquations(2 + count($gaps));
        $errors = [];

        foreach ($window as $i) {
            $gap = $gapOf($i);
            $error = log($repeats->seconds[$i] / ($repeats->predicted[$i] * exp($offsets[$gap] ?? 0.0)));
            $errors[$i] = $error;
            $row = [$repeats->spread[$i] ** 2, 1.0];

            foreach ($gaps as $candidateGap) {
                $row[] = $gap === $candidateGap ? 1.0 : 0.0;
            }

            $normal->add($row, min(abs($error), self::RESIDUAL_CLIP) ** 2);
        }

        $coefficients = $normal->solveNonNegative();
        $gapTerms = [];

        foreach ($gaps as $index => $gap) {
            $gapTerms[$gap] = round($coefficients[2 + $index], 6);
        }

        $parameters = $parameters->with(
            repeatSpreadWeight: round($coefficients[0], 6),
            repeatConstant: round($coefficients[1], 6),
            repeatGapTerms: $gapTerms,
            repeatGapOffsets: $offsets,
            repeatConsistencyEdges: PredictionModelFitter::quartileEdges(array_map(static fn (int $i): float => $repeats->spread[$i], $window)),
        );

        $byKey = [];

        foreach ($errors as $i => $error) {
            $gap = $gapOf($i);
            $z = $error / $parameters->repeatSigma($repeats->spread[$i], $gap);

            foreach (CandidatePredictionCalculator::repeatKeys(WindowBucket::quartile($repeats->spread[$i], $parameters->repeatConsistencyEdges), $repeats->group[$i], $gap) as $key) {
                $byKey[$key][] = $z;
            }
        }

        return [$parameters->with(quantiles: [...$parameters->quantiles, ...self::quantileTable($byKey)]), ['repeat_samples' => count($window)]];
    }

    /**
     * The posterior (or, switched off, the shown model's median from 5 on) of a sample with the fitted variances.
     *
     * @return array{0: float, 1: float}
     */
    private function logDifficulty(CandidateSamples $samples, int $s, CandidateModelParameters $parameters): array
    {
        $features = $parameters->features();
        $evidence = $samples->evidence($s);

        if ($features->on('posterior_all_k') === false && $evidence->k >= TimePredictionCalculator::RATED_INDICES && $samples->median($s) !== null && $evidence->weightSum > 0.0) {
            return [$samples->median($s), 1.0 / $evidence->weightSum];
        }

        return $evidence->posterior($samples->priorLog($s), $parameters->priorVariance($features->on('prior_types') ? $samples->priorType($s) : 'pooled'));
    }

    /**
     * @param array<string, list<float>> $byKey
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function quantileTable(array $byKey): array
    {
        $table = [];

        foreach ($byKey as $key => $values) {
            if (count($values) < self::MINIMUM_KEY_SAMPLES) {
                continue;
            }

            sort($values);
            $table[$key] = [
                round(PredictionModelFitter::quantile($values, 0.10), 4),
                round(PredictionModelFitter::quantile($values, 0.25), 4),
                round(PredictionModelFitter::quantile($values, 0.75), 4),
                round(PredictionModelFitter::quantile($values, 0.90), 4),
            ];
        }

        return $table;
    }
}
