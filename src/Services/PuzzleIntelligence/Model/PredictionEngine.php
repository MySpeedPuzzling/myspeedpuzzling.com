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

    /** @var array<string, null|float> the prior's log difficulty per puzzle, for $priorsOf */
    private array $priorLogs = [];
    private null|PriorLookup $priorsOf = null;

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
     * Drops the as-of difficulty (k, sums, scores) and the priors read with it - for the candidate model's daily fit
     * (CandidateModelRecalculator), which needs only the drift states after its samples are taken and runs in a 512 MB
     * container. Nothing of the shown model calls it; inputsFor() / inputsAt() are wrong afterwards.
     */
    public function releaseAsOfDifficulty(): void
    {
        $this->k = [];
        $this->sumLog = [];
        $this->score = [];
        $this->priorLogs = [];
        $this->priorsOf = null;
    }

    /**
     * The puzzles' difficulty as of every attempt: per puzzle, the indices in the order they became known and the
     * attempts in their order, merged.
     */
    public function computeAsOfDifficulty(): void
    {
        $set = $this->set;

        foreach ($set->puzzles() as $puzzle) {
            // Chronological already (FirstAttemptSet)
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
     * indices counts as its prior, the average puzzle without one). A 0-second time is no measurement (it has no log -
     * the pace history and the difficulty skip it the same way) and leaves the drift as it was.
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

                if ($row !== null && $row['baseline'] > 0 && $set->seconds[$i] > 0) {
                    $prior = $this->k[$i] < TimePredictionCalculator::RATED_INDICES ? $this->priorLog($i, $priors) : null;
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
     * The prior's log difficulty of the attempt's puzzle (null = none), once per puzzle and prior lookup.
     */
    public function priorLog(int $i, PriorLookup $priors): null|float
    {
        if ($this->priorsOf !== $priors) {
            $this->priorsOf = $priors;
            $this->priorLogs = [];
        }

        $puzzle = $this->set->puzzle[$i];

        if (!array_key_exists($puzzle, $this->priorLogs)) {
            $this->priorLogs[$puzzle] = $this->calculator->prior($priors->contextFor($puzzle, $this->set->pieces[$i]))?->logDifficulty;
        }

        return $this->priorLogs[$puzzle];
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

    /**
     * The inputs of a prediction for a solve that is no qualifying first attempt (the candidate's unboxed first tries)
     * at a moment: the same rules as inputsFor(), the puzzle's difficulty from the indices known before the moment.
     */
    public function inputsAt(string $player, string $puzzle, int $pieces, float $solved, float $tracked, PriorLookup $priors): StatisticalPredictionInputs
    {
        $pairKey = $player . '|' . $pieces;
        $own = $this->timeline->directRowBefore($player, $pieces, $solved, $tracked);
        $ownState = $this->stateAt($pairKey, $solved, $tracked);
        $before = $this->countBefore($pairKey, $solved, $tracked);
        $ownBaseline = $own['baseline'] ?? null;

        if ($ownBaseline === null && $before >= 1) {
            $ownBaseline = $this->timeline->paceBefore($player, $pieces, $solved, $tracked);
        }

        $five = $pieces !== 500 ? $this->timeline->directRowBefore($player, 500, $solved, $tracked) : null;
        $fiveState = $five !== null ? $this->stateAt($player . '|500', $solved, $tracked) : null;
        [$k, $sumLog, $score] = $this->difficultyAt($puzzle, $solved, $tracked);

        return new StatisticalPredictionInputs(
            piecesCount: $pieces,
            ownBaselineSeconds: $ownBaseline,
            ownBaselineDirect: $own !== null,
            ownQualifyingCount: $own !== null ? $before : 0,
            ownDrift: $own !== null ? $ownState[0] : null,
            ownSpread: $own !== null ? $ownState[1] : null,
            pace500Seconds: $five['baseline'] ?? null,
            drift500: $fiveState[0] ?? null,
            spread500: $fiveState[1] ?? null,
            kIndices: $k,
            sumLogIndices: $sumLog,
            difficultyScore: $score,
            prior: $priors->contextFor($puzzle, $pieces),
        );
    }

    /**
     * The puzzle's index count, sum of log indices and median score (k >= 5) from the indices known before a moment.
     *
     * @return array{0: int, 1: float, 2: null|float}
     */
    public function difficultyAt(string $puzzle, float $solved, float $tracked): array
    {
        $indices = [];

        foreach ($this->set->attemptsOfPuzzle($puzzle) as $i) {
            $index = $this->set->index[$i];

            if ($index === null) {
                continue;
            }

            [$knownSolved, $knownTracked] = $this->set->knownFrom($i);

            if (($knownSolved <=> $solved ?: $knownTracked <=> $tracked) < 0) {
                $indices[] = $index;
            }
        }

        $k = count($indices);
        $sum = array_sum(array_map('log', $indices));

        if ($k < TimePredictionCalculator::RATED_INDICES) {
            return [$k, $sum, null];
        }

        sort($indices);
        $middle = intdiv($k, 2);

        return [$k, $sum, round($k % 2 === 0 ? ($indices[$middle - 1] + $indices[$middle]) / 2.0 : $indices[$middle], 4)];
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
    public function stateBefore(string $pairKey, int $i): array
    {
        return $this->stateAt($pairKey, $this->set->solved[$i], $this->set->tracked[$i]);
    }

    /**
     * The pair's attempts strictly before a moment.
     */
    public function countBefore(string $pairKey, float $solved, float $tracked): int
    {
        $pair = $this->set->pair($pairKey);

        if ($pair === null) {
            return 0;
        }

        [$low, $pairCount] = $pair;
        $start = $low;
        $high = $low + $pairCount;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (($this->set->solved[$middle] <=> $solved ?: $this->set->tracked[$middle] <=> $tracked) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low - $start;
    }

    /**
     * Drift and spread of a pair right before a moment: after its last attempt before it.
     *
     * @return array{0: null|float, 1: null|float}
     */
    public function stateAt(string $pairKey, float $solved, float $tracked): array
    {
        $pair = $this->set->pair($pairKey);

        if ($pair === null) {
            return [null, null];
        }

        [$low, $pairCount] = $pair;
        $high = $low + $pairCount;
        $start = $low;

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
