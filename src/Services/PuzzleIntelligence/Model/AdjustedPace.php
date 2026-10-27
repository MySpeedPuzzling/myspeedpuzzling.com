<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SplFixedArray;

/**
 * The candidate's difficulty-adjusted pace (v7, prediction model review F7/P9): the shown pace is the decay-weighted
 * median of a player's raw seconds at a piece count, so a player who picks harder puzzles gets a slower pace - and,
 * multiplied by the puzzle's difficulty, a prediction that is too slow (and the puzzles they solve look easier than they
 * are). The two-way pace takes the median of ln seconds - the puzzle's log difficulty instead (the player's level on
 * the average puzzle), with the same weights, and the drift once more against it - the shown drift absorbs part of the
 * selection (its residuals against the raw pace carry it, shrunk by n ÷ (n + 5)), so keeping it would count that part
 * twice.
 *
 * Per pace row (a pair's attempt from its 5th on) one number, the offset = (ln adjusted pace + its drift) - (ln raw pace
 * + the shown drift): the candidate adds it wherever it reads the solver's pace and drift - the pace rows of the size
 * curve and every difficulty index (CandidateEngine) - so the puzzles' difficulty and the players' paces are estimated
 * jointly: an alternating fixed point (CandidateModelFitter::adjustPace() - the puzzles' difficulty from the adjusted
 * indices, the paces from that difficulty, again).
 *
 * The puzzles' difficulty is each one's candidate posterior as known at one moment (the fit's end - the evaluation's
 * cut-off; live the present), leaving out the player's own index of it (a player's own time must not move their own
 * pace twice). Rows are keyed by the attempt like the drift (PredictionEngine::stateAt()): the offset of a pair at a
 * moment is the one after its last attempt strictly before it.
 */
final class AdjustedPace
{
    private const float PLATEAU_MONTHS = 3.0;
    private const float HALF_LIFE_MONTHS = 13.5;
    private const float SECONDS_PER_MONTH = 30.4375 * 86400.0;
    /** An offset moves only by this much (log) or more, rounded to 4 decimals - what candidate_pace_offset stores (a row where it moved), so the live path, the refresh and the fit read the very same numbers */
    public const float STEP = 0.001;

    /** @var SplFixedArray<null|float> the pair's offset after the attempt (its 5th on) */
    private SplFixedArray $offsetAfter;
    /** @var SplFixedArray<null|float> the decay-weighted mean log difficulty of the pair's puzzles up to the attempt - what the player picks */
    private SplFixedArray $selectionAfter;

    public function __construct(
        private readonly FirstAttemptSet $set,
    ) {
        $this->offsetAfter = new SplFixedArray($set->count());
        $this->selectionAfter = new SplFixedArray($set->count());
    }

    /**
     * Every pair's offsets (quantised as stored) and selections.
     *
     * @param SplFixedArray<null|float> $difficulty the log difficulty of every attempt's puzzle (as of the moment, without the attempt's own index), null = none
     * @param array<int, null|float> $driftAfter the shown drift of the pair after every attempt (PredictionEngine::$driftAfter)
     */
    public static function compute(FirstAttemptSet $set, SplFixedArray $difficulty, array $driftAfter): self
    {
        $adjusted = new self($set);

        foreach ($set->pairs() as [$start, $count]) {
            $adjusted->computePair($start, $count, $difficulty, $driftAfter);
        }

        $adjusted->quantize();

        return $adjusted;
    }

    /**
     * One pair in its order: after each attempt from the 5th on, the raw and the adjusted weighted medians over the
     * attempts so far (one sorted order each, kept by insertion; the weights by age to that attempt) and the drift
     * against the adjusted pace (TimePredictionCalculator::drift() over the residuals of the attempts with a pace before
     * them, each against the adjusted pace then and its puzzle's difficulty).
     *
     * @param SplFixedArray<null|float> $difficulty
     * @param array<int, null|float> $driftAfter
     */
    private function computePair(int $start, int $count, SplFixedArray $difficulty, array $driftAfter): void
    {
        $set = $this->set;
        // The pair's measured attempts so far: moments, ln seconds, ln seconds - log difficulty, log difficulty
        $moments = [];
        $raw = [];
        $adjustedValues = [];
        $logDifficulty = [];
        $rawOrder = [];
        $adjustedOrder = [];
        $residuals = [];
        $lastAdjustedPace = null;

        for ($i = $start, $end = $start + $count; $i < $end; $i++) {
            $d = $difficulty[$i];
            $seconds = $set->seconds[$i];

            // A 0-second time has no log, a puzzle without a difficulty no adjusted time: neither counts (the pace
            // history and the drift skip 0-second times the same way)
            if ($seconds > 0 && $d !== null) {
                $logSeconds = log($seconds);

                if ($lastAdjustedPace !== null) {
                    $residuals[] = $logSeconds - $lastAdjustedPace - $d;
                }

                $element = count($moments);
                $moments[] = $set->solved[$i];
                $raw[] = $logSeconds;
                $adjustedValues[] = $logSeconds - $d;
                $logDifficulty[] = $d;
                self::insert($rawOrder, $raw, $element);
                self::insert($adjustedOrder, $adjustedValues, $element);
            }

            if ($set->position[$i] < PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT || $moments === []) {
                continue;
            }

            // The pace's weights: ages to this attempt, a plateau, then the half-life (PlayerBaselineCalculator)
            $now = $set->solved[$i];
            $weights = [];
            $total = 0.0;
            $weightedDifficulty = 0.0;

            foreach ($moments as $element => $moment) {
                $age = max(0.0, ($now - $moment) / self::SECONDS_PER_MONTH - self::PLATEAU_MONTHS);
                $weight = exp(-$age / self::HALF_LIFE_MONTHS);
                $weights[$element] = $weight;
                $total += $weight;
                $weightedDifficulty += $weight * $logDifficulty[$element];
            }

            $this->selectionAfter[$i] = $weightedDifficulty / $total;
            $adjustedPace = self::weightedMedian($adjustedOrder, $adjustedValues, $weights, $total);
            $lastAdjustedPace = $adjustedPace;
            // The raw median with the same weights (not the stored baseline): its weights' rounding cancels out
            $this->offsetAfter[$i] = $adjustedPace + TimePredictionCalculator::drift($residuals)
                - self::weightedMedian($rawOrder, $raw, $weights, $total) - ($driftAfter[$i] ?? 0.0);
        }
    }

    /**
     * Every pair's offsets as they are stored: rounded to 4 decimals, kept while they move less than STEP.
     */
    private function quantize(): void
    {
        $last = null;
        $lastPair = null;

        foreach ($this->offsetAfter as $i => $offset) {
            if ($offset === null) {
                continue;
            }

            $pairKey = $this->set->player[$i] . '|' . $this->set->pieces[$i];
            $offset = round($offset, 4);

            if ($pairKey === $lastPair && $last !== null && abs($offset - $last) < self::STEP) {
                $offset = $last;
            }

            $this->offsetAfter[$i] = $offset;
            [$last, $lastPair] = [$offset, $pairKey];
        }
    }

    /**
     * @param list<int> $order elements sorted by their value
     * @param list<float> $values
     */
    private static function insert(array &$order, array $values, int $element): void
    {
        $value = $values[$element];
        $low = 0;
        $high = count($order);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($values[$order[$middle]] <= $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        array_splice($order, $low, 0, [$element]);
    }

    /**
     * The weighted median the baseline takes (PlayerBaselineCalculator::computeWeightedMedian()): the first value in
     * the sorted order whose cumulative weight reaches half.
     *
     * @param list<int> $order
     * @param list<float> $values
     * @param array<int, float> $weights
     */
    private static function weightedMedian(array $order, array $values, array $weights, float $total): float
    {
        $half = $total / 2.0;
        $cumulative = 0.0;

        foreach ($order as $element) {
            $cumulative += $weights[$element];

            if ($cumulative >= $half) {
                return $values[$element];
            }
        }

        return $values[$order[count($order) - 1]];
    }

    /**
     * The offset of the pair after the attempt (null before its 5th).
     */
    public function offsetAfter(int $i): null|float
    {
        return $this->offsetAfter[$i];
    }

    public function selectionAfter(int $i): null|float
    {
        return $i < $this->selectionAfter->getSize() ? $this->selectionAfter[$i] : null;
    }

    /**
     * The pair's offset at a moment: after its last attempt strictly before it (0 without one).
     */
    public function offsetAt(string $pairKey, float $solved, float $tracked): float
    {
        return $this->rowBefore($pairKey, $solved, $tracked) ?? 0.0;
    }

    /**
     * The pair's offset at a moment, null while it has no pace row before it.
     */
    public function rowBefore(string $pairKey, float $solved, float $tracked): null|float
    {
        $last = $this->lastBefore($pairKey, $solved, $tracked);

        return $last !== null ? $this->offsetAfter[$last] : null;
    }

    /**
     * The offsets as the daily fit stored them (candidate_pace_offset): every attempt from a pair's 5th on reads the
     * pair's latest stored row at its moment - a pace row written since the fit carries the last stored offset (the
     * 15-minute evidence refresh). No selections.
     */
    public static function fromStored(FirstAttemptSet $set, CandidatePaceOffsets $stored): self
    {
        $adjusted = new self($set);
        $adjusted->forgetSelection();

        for ($i = 0, $count = $set->count(); $i < $count; $i++) {
            if ($set->position[$i] >= PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
                $adjusted->offsetAfter[$i] = $stored->at($set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i], inclusive: true);
            }
        }

        return $adjusted;
    }

    /**
     * Drops what the players pick (only the evaluation's labels read it).
     */
    public function forgetSelection(): void
    {
        $this->selectionAfter = new SplFixedArray(0);
    }

    /**
     * What the player picked at the pair's size before a moment (the weighted mean log difficulty) - null before a pace.
     */
    public function selectionAt(string $pairKey, float $solved, float $tracked): null|float
    {
        $last = $this->lastBefore($pairKey, $solved, $tracked);

        return $last !== null ? $this->selectionAfter($last) : null;
    }

    /**
     * Every pace row's offset in the pair order: [attempt, offset] - what the daily fit stores.
     *
     * @return iterable<array{0: int, 1: float}>
     */
    public function rows(): iterable
    {
        foreach ($this->offsetAfter as $i => $offset) {
            if ($offset !== null) {
                yield [(int) $i, (float) $offset];
            }
        }
    }

    private function lastBefore(string $pairKey, float $solved, float $tracked): null|int
    {
        $pair = $this->set->pair($pairKey);

        if ($pair === null) {
            return null;
        }

        [$low, $count] = $pair;
        $start = $low;
        $high = $low + $count;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (($this->set->solved[$middle] <=> $solved ?: $this->set->tracked[$middle] <=> $tracked) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low === $start ? null : $low - 1;
    }
}
