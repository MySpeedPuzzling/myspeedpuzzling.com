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
use SpeedPuzzling\Web\Value\SizeCurve;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\TasteState;
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
        [$candidateParameters, $tasteStates] = $this->fitCandidate($context, $features ?? CandidateFeatures::kept());
        [$cut, $end] = [(float) $fitBefore->getTimestamp(), $until !== null ? (float) $until->getTimestamp() : INF];
        $candidateEngine = $context->candidateEngine();
        $events = self::afterTheCut($context, $cut, $end);
        $selectionEdges = self::selectionEdges($context, $events);

        $groups = ['statistical' => new EvaluationGroups(), 'blend' => new EvaluationGroups(), 'candidate' => new EvaluationGroups()];
        // Unboxed first tries (outside the qualifying set): the shown model as it predicts them, the candidate with its offset
        $unboxedGroups = ['statistical' => new EvaluationGroups(), 'candidate' => new EvaluationGroups()];
        $unboxed = $context->unboxed;

        // In the order of the solves: the players' taste states move on with every first try after the cut, as the
        // daily fit's would (the unboxed first tries read them and add nothing, like in the fit)
        foreach ($events as [$unboxedTry, $i]) {
            if ($unboxedTry) {
                $u = $i;
                $inputs = CandidateModelFitter::unboxedInputs($context->candidateEngine(), $unboxed, $u, $context->priors, $context->imageEstimates, $candidateParameters)
                    ->withCandidateTaste($tasteStates[$unboxed->player[$u]] ?? null, $context->pictures[$unboxed->puzzle[$u]] ?? null);
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

                continue;
            }

            $predictions = $this->predictAll($context, $candidateParameters, $i, $tasteStates);

            if ($predictions === null) {
                continue;
            }

            [$statistical, $blend, $candidate, $labels] = $predictions;
            $labels[] = 'experience ' . EvaluationGroups::experienceBucket($candidateEngine->experienceOf($i));
            $labels[] = self::selectionLabel($context, $i, $selectionEdges);
            $seconds = (int) $context->set->seconds[$i];


            foreach (['statistical' => $statistical, 'blend' => $blend, 'candidate' => $candidate] as $name => $prediction) {
                foreach ($labels as $label) {
                    $groups[$name]->add($label, $seconds, $prediction->predictedSeconds, $prediction->rangeLowSeconds, $prediction->rangeHighSeconds, (int) $prediction->range80LowSeconds, (int) $prediction->range80HighSeconds);
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
            [$parameters, $tasteStates] = $this->fitCandidate($context, $features);
            $groups = ['statistical' => new EvaluationGroups(), 'blend' => new EvaluationGroups(), 'candidate' => new EvaluationGroups()];

            foreach (self::afterTheCut($context, $cut, $end, withUnboxed: false) as [, $i]) {
                $predictions = $this->predictAll($context, $parameters, $i, $tasteStates);

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
            $withImageEstimates ? UnboxedFirstTries::load($this->connection) : UnboxedFirstTries::none(),
            $withImageEstimates ? PictureFactorsLookup::load($this->connection, $this->clock->now()) : [],
        );
    }

    /**
     * The candidate fitted before the cut-off, and every player's taste state at its end (what the stored states would
     * be: the window's first tries).
     *
     * @return array{0: CandidateModelParameters, 1: array<string, TasteState>}
     */
    private function fitCandidate(EvaluationContext $context, CandidateFeatures $features): array
    {
        $candidate = $context->candidateEngine();
        $states = [];
        $parameters = $this->candidateFitter->fit(
            $candidate,
            $context->priors,
            $context->repeats,
            $context->from,
            $context->cut,
            $context->fitBefore,
            $context->imageEstimates,
            $context->parameters,
            $features,
            $context->unboxed,
            $context->pictures,
            static function (string $player, float $solved, float $tracked, TasteState $state) use (&$states): void {
                $states[$player] = $state;
            },
            selectionLabels: true,
        );

        return [$parameters, $states];
    }

    /**
     * The first tries (and unboxed first tries) solved from the cut-off on, in the order of their moments: [unboxed,
     * index].
     *
     * @return list<array{0: bool, 1: int}>
     */
    private static function afterTheCut(EvaluationContext $context, float $cut, float $end, bool $withUnboxed = true): array
    {
        $set = $context->set;
        $events = [];

        foreach ($set->solved as $i => $solved) {
            if ($solved >= $cut && $solved < $end && $set->seconds[$i] > 0) {
                $events[] = [$solved, $set->tracked[$i], false, $i];
            }
        }

        if ($withUnboxed) {
            foreach ($context->unboxed->solved as $u => $solved) {
                if ($solved >= $cut && $solved < $end) {
                    $events[] = [$solved, $context->unboxed->tracked[$u], true, $u];
                }
            }
        }

        usort($events, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2] ?: $a[3] <=> $b[3]);

        return array_map(static fn (array $event): array => [$event[2], $event[3]], $events);
    }

    /**
     * The shown model's, the blend's and the candidate's prediction of the attempt, and its labels - null when any of
     * them has none (the comparison is on the same rows).
     *
     * @param array<string, TasteState> $tasteStates the players' taste states so far - moved on by this first try
     * @return null|array{0: TimePredictionResult, 1: TimePredictionResult, 2: TimePredictionResult, 3: list<string>}
     */
    private function predictAll(EvaluationContext $context, CandidateModelParameters $candidateParameters, int $i, array &$tasteStates = []): null|array
    {
        $engine = $context->engine;
        $inputs = $engine->inputsFor($i, $context->priors);
        $estimate = $context->imageEstimates[(string) $context->set->puzzle[$i]] ?? null;
        [$statistical, , $blend] = $this->calculator->sideBySide($inputs, $estimate, $context->parameters);
        $blend ??= $statistical;
        $candidateEngine = $context->candidateEngine();
        $player = (string) $context->set->player[$i];
        $picture = $context->pictures[(string) $context->set->puzzle[$i]] ?? null;
        $conditions = CandidateModelFitter::conditionsFor($candidateEngine, $i);
        $candidateInputs = CandidateModelFitter::inputsFor($candidateEngine, $i, $context->priors, $context->imageEstimates, $candidateParameters)
            ->withCandidateTaste($tasteStates[$player] ?? null, $picture);
        $candidate = $this->candidateCalculator->firstTry($candidateInputs, $candidateParameters, $conditions);

        // The player's taste state moves on with this first try's residual against the centre without the taste
        $taste = $candidateParameters->taste;

        if ($candidate !== null && $taste !== null && $candidateParameters->features()->on('taste')) {
            [$term] = CandidatePredictionCalculator::taste($candidateInputs, $candidateParameters, $conditions->competition, $candidate->logDifficulty);
            $residual = log((int) $context->set->seconds[$i] / $candidate->predictedSeconds) + $term;
            $tasteStates[$player] = ($tasteStates[$player] ?? $taste->initialState())->with(
                $taste->vector($picture, (int) $context->set->pieces[$i]),
                max(-TasteFitter::RESIDUAL_CLIP, min(TasteFitter::RESIDUAL_CLIP, $residual)),
                $conditions->competition,
                $candidate->logDifficulty,
            );
        }

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

        // The size curve's groups: what the shown pace rests on, the player's first tries at the size before, and for a
        // size without an own pace the distance from 500
        $own = (int) $context->set->position[$i] - 1;
        $labels[] = 'shown pace ' . ($statistical->paceSource->value ?? '-');
        $labels[] = 'own first tries at the size ' . match (true) {
            $own === 0 => '0',
            $own <= 2 => '1-2',
            $own <= 4 => '3-4',
            $own <= 9 => '5-9',
            $own <= 19 => '10-19',
            $own <= 49 => '20-49',
            default => '50+',
        };

        if ($own < 5) {
            $labels[] = sprintf('no own pace · distance band %d · %s', SizeCurve::distanceBand((int) $context->set->pieces[$i]), $own === 0 ? 'never solved the size' : '1-4 own first tries');
        }

        if ($group === PiecesGroup::Thousand) {
            $labels[] = 'size pieces_1000 · pace ' . ($candidate->paceSource->value ?? '-');
        }

        $labels[] = sprintf('size %s · k %s', $group->value, match (true) {
            $k === 0 => '0',
            $k <= 4 => '1-4',
            default => '5+',
        });
        $labels[] = 'k ' . match (true) {
            $k === 0 => '0',
            $k <= 4 => '1-4',
            default => '5+',
        } . ' (all)';

        foreach ([AttemptFlags::UNFLAGGED => 'unflagged', AttemptFlags::AFTER_GROUP => 'after own pair/team solve', AttemptFlags::COMMENTED => 'with a comment', AttemptFlags::COMPETITION => 'competition'] as $flag => $name) {
            if (($flags & $flag) !== 0) {
                $labels[] = 'flag ' . $name;
            }
        }

        return [$statistical, $blend, $candidate, $labels];
    }

    /**
     * The deciles of what the players pick (the decay-weighted mean log difficulty of their first tries at the size before
     * the predicted one - CandidateEngine::$selection, from the raw indices, the same with adjusted_pace on or off) over
     * the first tries after the cut that have one: 9 edges.
     *
     * @param list<array{0: bool, 1: int}> $events
     * @return list<float>
     */
    private static function selectionEdges(EvaluationContext $context, array $events): array
    {
        $values = [];

        foreach ($events as [$unboxed, $i]) {
            $selection = $unboxed ? null : self::selectionOf($context, $i);

            if ($selection !== null) {
                $values[] = $selection;
            }
        }

        if (count($values) < 10) {
            return [];
        }

        sort($values);
        $edges = [];

        for ($decile = 1; $decile <= 9; $decile++) {
            $edges[] = PredictionModelFitter::quantile($values, $decile / 10);
        }

        return $edges;
    }

    private static function selectionOf(EvaluationContext $context, int $i): null|float
    {
        $set = $context->set;

        return $context->candidateEngine()->selection?->selectionAt($set->player[$i] . '|' . $set->pieces[$i], $set->solved[$i], $set->tracked[$i]);
    }

    /**
     * @param list<float> $edges
     */
    private static function selectionLabel(EvaluationContext $context, int $i, array $edges): string
    {
        $selection = self::selectionOf($context, $i);

        if ($selection === null || $edges === []) {
            return 'selection: no pace at the size';
        }

        $decile = 1;

        foreach ($edges as $edge) {
            if ($selection > $edge) {
                $decile++;
            }
        }

        return sprintf('selection decile %02d (1 = easiest picks)', $decile);
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
