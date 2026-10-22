<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimelineLoader;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * The time-split acceptance measurement of the prediction model (Insights plan §3.6): everything fitted on what was
 * solved before the cut-off - the prior groups on the indices before it, tau, the thin-size rules and the window
 * calibration on the 12 months before it - and every first attempt and repeat after it predicted as of its own
 * moment (PredictionEngine). Read only; nothing is written.
 */
final readonly class PredictionEvaluator
{
    public function __construct(
        private Connection $connection,
        private PaceTimelineLoader $paceTimelineLoader,
        private PlayerBaselineCalculator $baselineCalculator,
        private TimePredictionCalculator $calculator,
        private PredictionModelFitter $fitter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{parameters: array<string, mixed>, first_try: list<array<string, int|float|string>>, repeat: list<array<string, int|float|string>>, seconds: float}
     */
    public function evaluate(DateTimeImmutable $fitBefore, null|DateTimeImmutable $until = null): array
    {
        $startedAt = microtime(true);
        $cut = (float) $fitBefore->getTimestamp();
        $end = $until !== null ? (float) $until->getTimestamp() : INF;

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

        $parameters = $this->fitter->fit($engine, $priors, $repeats, $from, $cut, $fitBefore);

        $firstTry = new EvaluationGroups();

        foreach ($set->solved as $i => $solved) {
            // A 0-second time is no measurement (no log)
            if ($solved < $cut || $solved >= $end || $set->seconds[$i] <= 0) {
                continue;
            }

            $inputs = $engine->inputsFor($i, $priors);
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
}
