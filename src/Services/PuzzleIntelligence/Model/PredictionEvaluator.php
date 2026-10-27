<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimelineLoader;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * The time-split acceptance measurement of the prediction model (Insights plan §3.6): everything fitted on what was
 * solved before the cut-off - the prior groups on the indices before it, tau, the thin-size rules and the window
 * calibration on the 12 months before it - and every first attempt and repeat after it predicted as of its own
 * moment (PredictionEngine). Read only; nothing is written.
 *
 * With the candidate (candidate(), `--candidate`): the candidate model (v4) fitted the same way, and every first try
 * after the cut-off predicted by the shown model (statistical), its side-by-side blend (the out-of-fold image estimate
 * as the prior) and the candidate, on the same rows - per k, size, pace source, prior type, experience and flag; the
 * repeats by the gap since the last solve. ablation() refits the candidate once without each of its parts.
 */
final readonly class PredictionEvaluator
{
    private const array K_LABELS = [[0, 0, '0'], [1, 2, '1-2'], [3, 4, '3-4'], [5, 9, '5-9'], [10, 19, '10-19'], [20, PHP_INT_MAX, '20+']];

    public function __construct(
        private Connection $connection,
        private PaceTimelineLoader $paceTimelineLoader,
        private PlayerBaselineCalculator $baselineCalculator,
        private TimePredictionCalculator $calculator,
        private PredictionModelFitter $fitter,
        private ClockInterface $clock,
        private CandidateModelFitter $candidateFitter = new CandidateModelFitter(),
        private CandidatePredictionCalculator $candidateCalculator = new CandidatePredictionCalculator(),
    ) {
    }

    /**
     * @return array{parameters: array<string, mixed>, first_try: list<array<string, int|float|string>>, repeat: list<array<string, int|float|string>>, seconds: float}
     */
    public function evaluate(DateTimeImmutable $fitBefore, null|DateTimeImmutable $until = null): array
    {
        $startedAt = microtime(true);
        $context = $this->prepare($fitBefore, withImageEstimates: false);
        [$cut, $end] = [(float) $fitBefore->getTimestamp(), $until !== null ? (float) $until->getTimestamp() : INF];
        $set = $context->set;
        $engine = $context->engine;
        $parameters = $context->parameters;

        $firstTry = new EvaluationGroups();

        foreach ($set->solved as $i => $solved) {
            // A 0-second time is no measurement (no log)
            if ($solved < $cut || $solved >= $end || $set->seconds[$i] <= 0) {
                continue;
            }

            $inputs = $engine->inputsFor($i, $context->priors);
            $todayRule = $inputs->kIndices >= TimePredictionCalculator::RATED_INDICES && $inputs->ownBaselineSeconds !== null;
            $prediction = $this->calculator->statistical($inputs, $parameters, allowPriorOnly: true);
            $group = PiecesGroup::fromPiecesCount((int) $set->pieces[$i]);
            $firstTry->count($todayRule);

            if ($prediction === null) {
                continue;
            }

            $seconds = (int) $set->seconds[$i];
            $k = $inputs->kIndices;
            $bucket = (string) $prediction->windowBucket;
            preg_match('/\|c(\d)\|/', $bucket, $consistency);

            $labels = ['with any prior (k >= 0)'];

            if ($k >= 1) {
                $labels[] = 'shown (k >= 1)';
                $labels[] = 'consistency quartile ' . (($consistency[1] ?? '0') + 1) . ' (k >= 1)';
                $labels[] = 'size ' . $group->value . ' (k >= 1)';
            }

            if ($k >= TimePredictionCalculator::RATED_INDICES) {
                $labels[] = 'rated puzzles (k >= 5)';
            }

            $labels[] = 'k bucket ' . match (WindowBucket::kBucket($k)) {
                0 => '0',
                1 => '1-4',
                5 => '5-19',
                default => '20+',
            };

            foreach ($labels as $label) {
                $firstTry->add($label, $seconds, $prediction->predictedSeconds, $prediction->rangeLowSeconds, $prediction->rangeHighSeconds, (int) $prediction->range80LowSeconds, (int) $prediction->range80HighSeconds);
            }
        }

        $repeat = new EvaluationGroups();
        $repeats = $context->repeats;

        foreach ($repeats->solved as $i => $solved) {
            if ($solved < $cut || $solved >= $end) {
                continue;
            }

            $spread = $repeats->spread[$i];
            $group = $repeats->group[$i];
            $predicted = $repeats->predicted[$i];
            $bucket = WindowBucket::repeat($spread, $parameters->repeatConsistencyEdges, $group);
            $window = TimePredictionCalculator::window((float) $predicted, $parameters->repeatSigma($spread), $parameters->quantilesFor($bucket));

            foreach (['all repeats', 'consistency quartile ' . ($bucket->consistency + 1), 'size ' . $group->value] as $label) {
                $repeat->add($label, $repeats->seconds[$i], $predicted, $window[1], $window[2], $window[0], $window[3]);
            }
        }

        return [
            'parameters' => $parameters->toArray(),
            'first_try' => $firstTry->report(),
            'repeat' => $repeat->report(),
            'seconds' => round(microtime(true) - $startedAt, 1),
        ];
    }

    /**
     * The shown model, its blend and the candidate on the same first tries (and repeats) after the cut-off.
     *
     * @return array{shown_parameters: array<string, mixed>, candidate_parameters: array<string, mixed>, first_try: list<array<string, int|float|string>>, repeat: list<array<string, int|float|string>>, unboxed: list<array<string, int|float|string>>, seconds: float}
     */
    public function candidate(DateTimeImmutable $fitBefore, null|DateTimeImmutable $until = null, null|CandidateFeatures $features = null): array
    {
        $startedAt = microtime(true);
        $context = $this->prepare($fitBefore, withImageEstimates: true);
        $candidateParameters = $this->fitCandidate($context, $features ?? CandidateFeatures::kept());
        [$cut, $end] = [(float) $fitBefore->getTimestamp(), $until !== null ? (float) $until->getTimestamp() : INF];
        $candidateEngine = $context->candidateEngine();

        $groups = ['statistical' => new EvaluationGroups(), 'blend' => new EvaluationGroups(), 'candidate' => new EvaluationGroups()];

        foreach ($context->set->solved as $i => $solved) {
            if ($solved < $cut || $solved >= $end || $context->set->seconds[$i] <= 0) {
                continue;
            }

            $predictions = $this->predictAll($context, $candidateParameters, $i);

            if ($predictions === null) {
                continue;
            }

            [$statistical, $blend, $candidate, $labels] = $predictions;
            $labels[] = 'experience ' . EvaluationGroups::experienceBucket($candidateEngine->experienceOf($i));
            $seconds = (int) $context->set->seconds[$i];

            foreach (['statistical' => $statistical, 'blend' => $blend, 'candidate' => $candidate] as $name => $prediction) {
                foreach ($labels as $label) {
                    $groups[$name]->add($label, $seconds, $prediction->predictedSeconds, $prediction->rangeLowSeconds, $prediction->rangeHighSeconds, (int) $prediction->range80LowSeconds, (int) $prediction->range80HighSeconds);
                }
            }
        }

        // Unboxed first tries (outside the qualifying set): the shown model as it predicts them, the candidate with its offset
        $unboxedGroups = ['statistical' => new EvaluationGroups(), 'candidate' => new EvaluationGroups()];
        $unboxed = $context->unboxed;

        for ($u = 0, $count = $unboxed->count(); $u < $count; $u++) {
            if ($unboxed->solved[$u] < $cut || $unboxed->solved[$u] >= $end) {
                continue;
            }

            $inputs = CandidateModelFitter::unboxedInputs($context->candidateEngine(), $unboxed, $u, $context->priors, $context->imageEstimates, $candidateParameters);
            $statistical = $this->calculator->statistical($inputs->withImageEstimate(null), $context->parameters, allowPriorOnly: true);
            $candidate = $this->candidateCalculator->firstTry($inputs, $candidateParameters, new SolveConditions(unboxed: true, competition: $unboxed->competition[$u]));

            if ($statistical === null || $candidate === null) {
                continue;
            }

            foreach (['statistical' => $statistical, 'candidate' => $candidate] as $name => $prediction) {
                foreach (['unboxed first tries', 'unboxed · k ' . self::kLabel((int) $inputs->kIndices)] as $label) {
                    $unboxedGroups[$name]->add($label, $unboxed->seconds[$u], $prediction->predictedSeconds, $prediction->rangeLowSeconds, $prediction->rangeHighSeconds, (int) $prediction->range80LowSeconds, (int) $prediction->range80HighSeconds);
                }
            }
        }

        $repeatGroups = ['statistical' => new EvaluationGroups(), 'candidate' => new EvaluationGroups()];
        $repeats = $context->repeats;
        $shown = $context->parameters;

        foreach ($repeats->solved as $i => $solved) {
            if ($solved < $cut || $solved >= $end) {
                continue;
            }

            $spread = $repeats->spread[$i];
            $predicted = $repeats->predicted[$i];
            $bucket = WindowBucket::repeat($spread, $shown->repeatConsistencyEdges, $repeats->group[$i]);
            $window = TimePredictionCalculator::window((float) $predicted, $shown->repeatSigma($spread), $shown->quantilesFor($bucket));
            $candidate = $this->candidateCalculator->repeatWindow(
                new TimePredictionResult($predicted, $window[1], $window[2], 0.0, true),
                $spread,
                $repeats->group[$i],
                $repeats->gapDays[$i],
                $candidateParameters,
            );
            $labels = ['all repeats', 'gap ' . TimePredictionCalculator::classifyGap($repeats->gapDays[$i]), 'size ' . $repeats->group[$i]->value];

            foreach ($labels as $label) {
                $repeatGroups['statistical']->add($label, $repeats->seconds[$i], $predicted, $window[1], $window[2], $window[0], $window[3]);
                $repeatGroups['candidate']->add($label, $repeats->seconds[$i], $candidate->predictedSeconds, $candidate->rangeLowSeconds, $candidate->rangeHighSeconds, (int) $candidate->range80LowSeconds, (int) $candidate->range80HighSeconds);
            }
        }

        return [
            'shown_parameters' => $shown->toArray(),
            'candidate_parameters' => $candidateParameters->toArray(),
            'first_try' => EvaluationGroups::sideBySide($groups),
            'repeat' => EvaluationGroups::sideBySide($repeatGroups),
            'unboxed' => EvaluationGroups::sideBySide($unboxedGroups),
            'seconds' => round(microtime(true) - $startedAt, 1),
        ];
    }

    /**
     * The candidate fitted once with every part and once without each part (the review's proposals), measured on the
     * same first tries after the cut-off: MAE per k bucket and overall, 50 / 80 % hits.
     *
     * @param list<string> $only measure only without these parts (empty = every part)
     * @return array{rows: list<array<string, int|float|string>>, seconds: float}
     */
    public function ablation(DateTimeImmutable $fitBefore, null|DateTimeImmutable $until = null, array $only = []): array
    {
        $startedAt = microtime(true);
        $context = $this->prepare($fitBefore, withImageEstimates: true);
        [$cut, $end] = [(float) $fitBefore->getTimestamp(), $until !== null ? (float) $until->getTimestamp() : INF];
        $variants = ['all parts' => CandidateFeatures::all()];

        foreach ($only !== [] ? $only : array_keys(CandidateFeatures::NAMES) as $name) {
            $variants['without ' . $name] = CandidateFeatures::without([$name]);
        }

        $rows = [];
        $baseRows = null;

        foreach ($variants as $variant => $features) {
            $parameters = $this->fitCandidate($context, $features);
            $groups = ['statistical' => new EvaluationGroups(), 'blend' => new EvaluationGroups(), 'candidate' => new EvaluationGroups()];

            foreach ($context->set->solved as $i => $solved) {
                if ($solved < $cut || $solved >= $end || $context->set->seconds[$i] <= 0) {
                    continue;
                }

                $predictions = $this->predictAll($context, $parameters, $i);

                if ($predictions === null) {
                    continue;
                }

                [$statistical, $blend, $candidate, $labels] = $predictions;
                $kLabel = array_values(array_filter($labels, static fn (string $label): bool => str_starts_with($label, 'k ')))[0];
                $seconds = (int) $context->set->seconds[$i];

                foreach (['statistical' => $statistical, 'blend' => $blend, 'candidate' => $candidate] as $name => $prediction) {
                    if ($baseRows !== null && $name !== 'candidate') {
                        continue;
                    }

                    foreach (['all', $kLabel] as $label) {
                        $groups[$name]->add($label, $seconds, $prediction->predictedSeconds, $prediction->rangeLowSeconds, $prediction->rangeHighSeconds, (int) $prediction->range80LowSeconds, (int) $prediction->range80HighSeconds);
                    }
                }
            }

            if ($baseRows === null) {
                $baseRows = true;
                $rows[] = ['variant' => 'shown (statistical v3)', ...$groups['statistical']->compact()];
                $rows[] = ['variant' => 'shown blend (v3 + image)', ...$groups['blend']->compact()];
            }

            $rows[] = ['variant' => 'candidate ' . $variant, ...$groups['candidate']->compact()];
        }

        return ['rows' => $rows, 'seconds' => round(microtime(true) - $startedAt, 1)];
    }

    private function prepare(DateTimeImmutable $fitBefore, bool $withImageEstimates): EvaluationContext
    {
        $cut = (float) $fitBefore->getTimestamp();
        $timeline = $this->paceTimelineLoader->all();
        $set = FirstAttemptSet::build($this->baselineCalculator->iterateQualifyingFirstAttempts(paceOrder: true), $timeline);
        $engine = new PredictionEngine($set, $timeline, $this->calculator);
        $engine->computeAsOfDifficulty();

        /** @var array<string, null|string> $manufacturers */
        $manufacturers = $this->connection->fetchAllKeyValue(
            'SELECT id, CASE WHEN ' . StatisticalInputsSql::NOT_SECRET . ' THEN manufacturer_id END FROM puzzle p',
            // Secret at the real present, not as of $fitBefore: a puzzle secret today stays out of every fit made today
            ['now' => $this->clock->now()->format('Y-m-d H:i:s')],
        );
        $priors = PriorLookup::fit($set, $manufacturers, $cut);
        $from = (float) $fitBefore->modify('-' . PredictionModelRecalculator::FIT_WINDOW_MONTHS . ' months')->getTimestamp();
        $repeats = RepeatHistory::load($this->connection, $from);
        $imageEstimates = $withImageEstimates ? $this->outOfFoldImageEstimates() : [];

        $parameters = $this->fitter->fit($engine, $priors, $repeats, $from, $cut, $fitBefore, $imageEstimates);

        return new EvaluationContext(
            $set,
            $engine,
            $priors,
            $repeats,
            $parameters,
            $imageEstimates,
            $from,
            $cut,
            $fitBefore,
            $withImageEstimates ? AttemptFlags::load($this->connection, $this->baselineCalculator, $set) : AttemptFlags::fromList([]),
            $withImageEstimates ? UnboxedFirstTries::load($this->connection) : UnboxedFirstTries::none()
        );
    }

    private function fitCandidate(EvaluationContext $context, CandidateFeatures $features): CandidateModelParameters
    {
        $candidate = $context->candidateEngine();

        return $this->candidateFitter->fit($candidate, $context->priors, $context->repeats, $context->from, $context->cut, $context->fitBefore, $context->imageEstimates, $context->parameters, $features, $context->unboxed);
    }

    /**
     * The shown model's, the blend's and the candidate's prediction of the attempt, and its labels - null when any of
     * them has none (the comparison is on the same rows).
     *
     * @return null|array{0: TimePredictionResult, 1: TimePredictionResult, 2: TimePredictionResult, 3: list<string>}
     */
    private function predictAll(EvaluationContext $context, CandidateModelParameters $candidateParameters, int $i): null|array
    {
        $engine = $context->engine;
        $inputs = $engine->inputsFor($i, $context->priors);
        $estimate = $context->imageEstimates[(string) $context->set->puzzle[$i]] ?? null;
        [$statistical, , $blend] = $this->calculator->sideBySide($inputs, $estimate, $context->parameters);
        $blend ??= $statistical;
        $candidateEngine = $context->candidateEngine();
        $candidateInputs = CandidateModelFitter::inputsFor($candidateEngine, $i, $context->priors, $context->imageEstimates, $candidateParameters);
        $candidate = $this->candidateCalculator->firstTry($candidateInputs, $candidateParameters, CandidateModelFitter::conditionsFor($candidateEngine, $i));

        if ($statistical === null || $blend === null || $candidate === null) {
            return null;
        }

        $k = $inputs->kIndices;
        $group = PiecesGroup::fromPiecesCount((int) $context->set->pieces[$i]);
        $flags = $context->flags->of($i);
        $labels = ['all', 'k ' . self::kLabel($k), 'size ' . $group->value, 'pace ' . ($candidate->paceSource->value ?? '-')];

        if ($k <= 4) {
            $labels[] = sprintf('k %s · prior %s', $k === 0 ? '0' : '1-4', $estimate !== null ? 'image' : ($candidate->priorLog !== null ? 'brand' : 'none'));
        }

        if ($k >= TimePredictionCalculator::RATED_INDICES && $candidateInputs->candidateEvidence !== null) {
            $dispersion = $candidateInputs->candidateEvidence->dispersion($candidateParameters->dispersionShrink);
            $labels[] = 'dispersion ' . match (true) {
                $dispersion <= 0.0 => '0',
                $dispersion < 0.003 => '< 0.003',
                $dispersion < 0.008 => '0.003-0.008',
                $dispersion < 0.02 => '0.008-0.02',
                default => '>= 0.02',
            } . ' (k >= 5)';
        }

        if ($group === PiecesGroup::Thousand) {
            $labels[] = 'size pieces_1000 · pace ' . ($candidate->paceSource->value ?? '-');
        }

        foreach ([AttemptFlags::UNFLAGGED => 'unflagged', AttemptFlags::AFTER_GROUP => 'after own pair/team solve', AttemptFlags::COMMENTED => 'with a comment', AttemptFlags::COMPETITION => 'competition'] as $flag => $name) {
            if (($flags & $flag) !== 0) {
                $labels[] = 'flag ' . $name;
            }
        }

        return [$statistical, $blend, $candidate, $labels];
    }

    /**
     * Out-of-fold estimates of the puzzles' current pictures - the side-by-side predictors' reconstruction rule.
     *
     * @return array<string, ImagePriorInput>
     */
    private function outOfFoldImageEstimates(): array
    {
        $estimates = [];
        $rows = $this->connection->iterateAssociative('
            SELECT p.id, ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS . '
            FROM puzzle p
            ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: true) . '
            WHERE img.puzzle_id IS NOT NULL
        ', ['now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $estimate = ImagePriorInput::fromRow($row);

            if ($estimate !== null && is_string($row['id'])) {
                $estimates[$row['id']] = $estimate;
            }
        }

        return $estimates;
    }


    private static function kLabel(int $k): string
    {
        foreach (self::K_LABELS as [$low, $high, $label]) {
            if ($k >= $low && $k <= $high) {
                return $label;
            }
        }

        return '20+';
    }
}
