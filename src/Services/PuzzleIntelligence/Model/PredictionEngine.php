<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

/**
 * The statistical model replayed over every qualifying first attempt, each as of its own moment - what the batch
 * stores as drift and spread on player_pace_history, what the daily fit learns tau, the thin-size rules and the
 * window calibration from, and what the evaluation command measures (README "Step 8: Time Prediction").
 *
 * As of an attempt means: the puzzle's difficulty from the indices known strictly before it (an index is known from
 * its solve on, a solver's "first pace" index only from that pace's row - FirstAttemptSet), the player's pace rows
 * before it, and the drift and spread of their attempts before it. Nothing from the attempt itself or later is used;
 * the prior is the one handed in (the evaluation fits it before its cut-off).
 */
final class PredictionEngine
{
    /** @var array<int, int> as-of index count */
    public array $k;
    /** @var array<int, float> as-of sum of log indices */
    public array $sumLog;
    /** @var array<int, null|float> as-of median index, rounded like the stored difficulty score (k >= 5) */
    public array $score;

    /** @var array<int, null|float> drift of the attempt's pair right after it (null before its 5th attempt) */
    public array $driftAfter;
    /** @var array<int, null|float> */
    public array $spreadAfter;

    public function __construct(
        public readonly FirstAttemptSet $set,
        public readonly PaceTimeline $timeline,
        private readonly TimePredictionCalculator $calculator,
    ) {
        $count = $set->count();
        $this->k = array_fill(0, $count, 0);
        $this->sumLog = array_fill(0, $count, 0.0);
        $this->score = array_fill(0, $count, null);
        $this->driftAfter = array_fill(0, $count, null);
        $this->spreadAfter = array_fill(0, $count, null);
    }

    public function calculator(): TimePredictionCalculator
    {
        return $this->calculator;
    }

    /**
     * The puzzles' difficulty as of every attempt: per puzzle, the indices in the order they became known and the
     * attempts in their order, merged.
     */
    public function computeAsOfDifficulty(): void
    {
        $set = $this->set;

        foreach ($set->puzzles() as $puzzle) {
            $attempts = $set->attemptsOfPuzzle($puzzle);
            // The indices as parallel lists - no array per index
            $knownSolved = [];
            $knownTracked = [];
            $indices = [];

            foreach ($attempts as $i) {
                $index = $set->index[$i];

                if ($index !== null) {
                    [$knownSolved[], $knownTracked[]] = $set->knownFrom($i);
                    $indices[] = $index;
                }
            }

            if ($indices === []) {
                continue;
            }

            array_multisort($knownSolved, $knownTracked, $indices);
            usort($attempts, static fn (int $a, int $b): int => ($set->solved[$a] <=> $set->solved[$b]) ?: ($set->tracked[$a] <=> $set->tracked[$b]));

            $sorted = [];
            $sum = 0.0;
            $next = 0;
            $eventCount = count($indices);

            foreach ($attempts as $i) {
                while ($next < $eventCount) {
                    if (($knownSolved[$next] <=> $set->solved[$i] ?: $knownTracked[$next] <=> $set->tracked[$i]) >= 0) {
                        break;
                    }

                    self::insertSorted($sorted, $indices[$next]);
                    $sum += log($indices[$next]);
                    $next++;
                }

                $k = count($sorted);
                $this->k[$i] = $k;
                $this->sumLog[$i] = $sum;

                if ($k >= TimePredictionCalculator::RATED_INDICES) {
                    $middle = intdiv($k, 2);
                    $median = $k % 2 === 0 ? ($sorted[$middle - 1] + $sorted[$middle]) / 2.0 : $sorted[$middle];
                    $this->score[$i] = round($median, 4);
                }
            }
        }
    }

    /**
     * Drift and spread of every pair after each of its attempts, from the residuals of the attempts with a direct pace
     * before them: ln(actual) - ln(pace) - log difficulty (the model's, with this prior and tau; a puzzle without
     * indices counts as its prior, the average puzzle without one).
     */
    public function computeDrift(PriorLookup $priors, float $tau): void
    {
        $set = $this->set;

        foreach ($set->pairs() as [$start, $pairCount]) {
            $residuals = [];
            $errors = [];
            $drift = null;

            for ($i = $start, $end = $start + $pairCount; $i < $end; $i++) {
                $row = $this->timeline->directRowBefore($set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i]);

                if ($row !== null && $row['baseline'] > 0) {
                    $prior = $this->k[$i] < TimePredictionCalculator::RATED_INDICES
                        ? $this->calculator->prior($priors->contextFor($set->puzzle[$i], $set->pieces[$i]))?->logDifficulty
                        : null;
                    $difficulty = TimePredictionCalculator::logDifficulty($this->k[$i], $this->sumLog[$i], $this->score[$i], $prior, $tau);
                    $logDifficulty = $difficulty[0] ?? $prior ?? 0.0;
                    $residual = log($set->seconds[$i] / $row['baseline']) - $logDifficulty;
                    $residuals[] = $residual;
                    $errors[] = $residual - ($drift ?? 0.0);
                }

                if ($set->position[$i] >= PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
                    $drift = TimePredictionCalculator::drift($residuals);
                    $this->driftAfter[$i] = $drift;
                    $this->spreadAfter[$i] = TimePredictionCalculator::playerSpread($errors);
                }
            }
        }
    }

    /**
     * The inputs of the attempt's prediction as of its moment (computeAsOfDifficulty() and computeDrift() first).
     */
    public function inputsFor(int $i, PriorLookup $priors): StatisticalPredictionInputs
    {
        $set = $this->set;
        $player = $set->player[$i];
        $pieces = $set->pieces[$i];
        $position = $set->position[$i];
        $own = $this->timeline->directRowBefore($player, $pieces, $set->solved[$i], $set->tracked[$i]);
        $ownState = $this->stateBefore($player . '|' . $pieces, $i);
        $ownBaseline = $own['baseline'] ?? null;

        // A gap baseline only for a piece count the player has solved before (PlayerBaselineCalculator::findBaselineGaps())
        if ($ownBaseline === null && $position >= 2) {
            $ownBaseline = $this->timeline->paceBefore($player, $pieces, $set->solved[$i], $set->tracked[$i]);
        }

        $five = $pieces !== 500 ? $this->timeline->directRowBefore($player, 500, $set->solved[$i], $set->tracked[$i]) : null;
        $fiveState = $five !== null ? $this->stateBefore($player . '|500', $i) : null;

        return new StatisticalPredictionInputs(
            piecesCount: $pieces,
            ownBaselineSeconds: $ownBaseline,
            ownBaselineDirect: $own !== null,
            ownQualifyingCount: $own !== null ? $position - 1 : 0,
            ownDrift: $own !== null ? $ownState[0] : null,
            ownSpread: $own !== null ? $ownState[1] : null,
            pace500Seconds: $five['baseline'] ?? null,
            drift500: $fiveState[0] ?? null,
            spread500: $fiveState[1] ?? null,
            kIndices: (int) $this->k[$i],
            sumLogIndices: (float) $this->sumLog[$i],
            difficultyScore: $this->score[$i],
            prior: $priors->contextFor((string) $set->puzzle[$i], $pieces),
        );
    }

    public function predict(int $i, PriorLookup $priors, PredictionModelParameters $parameters, bool $allowPriorOnly = true): null|TimePredictionResult
    {
        return $this->calculator->statistical($this->inputsFor($i, $priors), $parameters, $allowPriorOnly);
    }

    /**
     * Drift and spread of a pair right before the moment of attempt $i: after its last attempt before that moment.
     *
     * @return array{0: null|float, 1: null|float}
     */
    private function stateBefore(string $pairKey, int $i): array
    {
        $pair = $this->set->pair($pairKey);

        if ($pair === null) {
            return [null, null];
        }

        [$low, $pairCount] = $pair;
        $high = $low + $pairCount;
        $start = $low;
        $solved = $this->set->solved[$i];
        $tracked = $this->set->tracked[$i];

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (($this->set->solved[$middle] <=> $solved ?: $this->set->tracked[$middle] <=> $tracked) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        if ($low === $start) {
            return [null, null];
        }

        return [$this->driftAfter[$low - 1], $this->spreadAfter[$low - 1]];
    }

    /**
     * @param list<float> $sorted
     */
    private static function insertSorted(array &$sorted, float $value): void
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        array_splice($sorted, $low, 0, [$value]);
    }
}
