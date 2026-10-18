<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

/**
 * Rows of player_pace_history in memory, for the as-of lookup of the difficulty indices - the one
 * implementation the batch, the incremental handler and PredictionReconstructor share (built by
 * PaceTimelineLoader). Moments are epoch seconds of (COALESCE(finished_at, tracked_at), tracked_at).
 *
 * The pace of a solver at a solve (paceAt()):
 * 1. the row of the solve's piece count valid just before it - strictly before in (solved, tracked);
 *    of two rows of the very same moment the later one (qualifying_count) counts;
 * 2. else interpolated/extrapolated from the rows valid just before it at the solver's other piece
 *    counts (PlayerBaselineCalculator::gapBaseline(), the rule of the baselines' second pass);
 * 3. else - the solve came before the solver had any pace (one of their first first attempts) - their
 *    first pace: 1 + 2 at the moment of their first row, when that row is known at the moment the
 *    difficulty is computed for ($knownBefore; the batch knows every row).
 */
final class PaceTimeline
{
    /** @var array<string, array<int, array{solved: list<float>, tracked: list<float>, baseline: list<int>, drift: list<null|float>, spread: list<null|float>, sorted: bool}>> player => piece count => rows ascending */
    private array $rows = [];

    public function __construct(
        private readonly PlayerBaselineCalculator $baselineCalculator,
        private null|float $scalingExponent = null,
        // Keep the rows' drift and spread (the reconstructor) - the batch computes its own and leaves them out
        private readonly bool $withStates = true,
    ) {
    }

    public function add(string $playerId, int $piecesCount, float $solved, float $tracked, int $baselineSeconds, null|float $drift = null, null|float $spread = null): void
    {
        if (!isset($this->rows[$playerId][$piecesCount])) {
            $this->rows[$playerId][$piecesCount] = ['solved' => [], 'tracked' => [], 'baseline' => [], 'drift' => [], 'spread' => [], 'sorted' => true];
        }

        $pair = &$this->rows[$playerId][$piecesCount];

        $last = count($pair['solved']) - 1;

        if ($last >= 0) {
            $order = ($solved <=> $pair['solved'][$last]) ?: ($tracked <=> $pair['tracked'][$last]);

            if ($order === 0) {
                // The same moment again: the same row loaded twice, or a later solve logged in the same
                // second - rows come in qualifying_count order, so the later one is the pace after both
                $pair['baseline'][$last] = $baselineSeconds;

                if ($this->withStates) {
                    $pair['drift'][$last] = $drift;
                    $pair['spread'][$last] = $spread;
                }

                return;
            }

            if ($order < 0) {
                $pair['sorted'] = false;
            }
        }

        $pair['solved'][] = $solved;
        $pair['tracked'][] = $tracked;
        $pair['baseline'][] = $baselineSeconds;

        if ($this->withStates) {
            $pair['drift'][] = $drift;
            $pair['spread'][] = $spread;
        }
    }

    /**
     * @param null|array{0: float, 1: float} $knownBefore (solved, tracked) of the moment the difficulty is computed for; null = every row is known
     */
    public function paceAt(string $playerId, int $piecesCount, float $solved, float $tracked, null|array $knownBefore = null): null|int
    {
        if (!isset($this->rows[$playerId])) {
            return null;
        }

        $this->sortPlayer($playerId);

        $pace = $this->lookup($playerId, $piecesCount, $solved, $tracked, inclusive: false);

        if ($pace !== null) {
            return $pace;
        }

        $first = $this->firstMoment($playerId);

        if ($knownBefore !== null && ([$first[0], $first[1]] <=> $knownBefore) >= 0) {
            return null;
        }

        return $this->lookup($playerId, $piecesCount, $first[0], $first[1], inclusive: true);
    }

    /**
     * paceAt() for every row known (the batch), with the moment from which that pace is known: the solve itself for
     * rules 1 and 2, the solver's first row for their first pace (rule 3) - an index is part of a puzzle's
     * difficulty only from then on (the as-of difficulty of PredictionEngine).
     *
     * @return null|array{0: int, 1: array{0: float, 1: float}}
     */
    public function paceWithKnownFrom(string $playerId, int $piecesCount, float $solved, float $tracked): null|array
    {
        if (!isset($this->rows[$playerId])) {
            return null;
        }

        $this->sortPlayer($playerId);

        $pace = $this->lookup($playerId, $piecesCount, $solved, $tracked, inclusive: false);

        if ($pace !== null) {
            return [$pace, [$solved, $tracked]];
        }

        $first = $this->firstMoment($playerId);
        $pace = $this->lookup($playerId, $piecesCount, $first[0], $first[1], inclusive: true);

        return $pace !== null ? [$pace, $first] : null;
    }

    /**
     * Rules 1 and 2 only - the pace a prediction at that moment had (a player's first pace, rule 3, is known only
     * later).
     */
    public function paceBefore(string $playerId, int $piecesCount, float $solved, float $tracked): null|int
    {
        if (!isset($this->rows[$playerId])) {
            return null;
        }

        $this->sortPlayer($playerId);

        return $this->lookup($playerId, $piecesCount, $solved, $tracked, inclusive: false);
    }

    /**
     * The pair's own row valid strictly before the moment (rule 1 only), with its drift and spread - the pace a
     * direct baseline and the drift of a prediction come from.
     *
     * @param null|array{0: float, 1: float} $knownBefore only rows before this moment count (the reconstructor)
     * @return null|array{baseline: int, drift: null|float, spread: null|float}
     */
    public function directRowBefore(string $playerId, int $piecesCount, float $solved, float $tracked, null|array $knownBefore = null): null|array
    {
        if (!isset($this->rows[$playerId][$piecesCount])) {
            return null;
        }

        $this->sortPlayer($playerId);

        if ($knownBefore !== null && ([$solved, $tracked] <=> $knownBefore) > 0) {
            [$solved, $tracked] = $knownBefore;
        }

        $pair = $this->rows[$playerId][$piecesCount];
        $index = $this->latestIndex($pair, $solved, $tracked, inclusive: false);

        if ($index === null) {
            return null;
        }

        return ['baseline' => $pair['baseline'][$index], 'drift' => $pair['drift'][$index] ?? null, 'spread' => $pair['spread'][$index] ?? null];
    }

    private function lookup(string $playerId, int $piecesCount, float $solved, float $tracked, bool $inclusive): null|int
    {
        $direct = isset($this->rows[$playerId][$piecesCount])
            ? $this->latest($this->rows[$playerId][$piecesCount], $solved, $tracked, $inclusive)
            : null;

        if ($direct !== null) {
            return $direct;
        }

        $others = [];

        foreach ($this->rows[$playerId] as $otherPiecesCount => $pair) {
            if ($otherPiecesCount === $piecesCount) {
                continue;
            }

            $baseline = $this->latest($pair, $solved, $tracked, $inclusive);

            // Interpolation works in logs: a zero baseline (0-second times) cannot take part
            if ($baseline !== null && $baseline > 0) {
                $others[$otherPiecesCount] = $baseline;
            }
        }

        if ($others === []) {
            return null;
        }

        ksort($others);

        $this->scalingExponent ??= $this->baselineCalculator->computeScalingExponent();

        return $this->baselineCalculator->gapBaseline($piecesCount, $others, $this->scalingExponent)['baseline_seconds'] ?? null;
    }

    /**
     * Baseline of the last row before (or at, when inclusive) the moment.
     *
     * @param array{solved: list<float>, tracked: list<float>, baseline: list<int>, drift: list<null|float>, spread: list<null|float>, sorted: bool} $pair
     */
    private function latest(array $pair, float $solved, float $tracked, bool $inclusive): null|int
    {
        $index = $this->latestIndex($pair, $solved, $tracked, $inclusive);

        return $index !== null ? $pair['baseline'][$index] : null;
    }

    /**
     * @param array{solved: list<float>, tracked: list<float>, baseline: list<int>, drift: list<null|float>, spread: list<null|float>, sorted: bool} $pair
     */
    private function latestIndex(array $pair, float $solved, float $tracked, bool $inclusive): null|int
    {
        $low = 0;
        $high = count($pair['solved']);

        // First index whose row is not before (or at) the moment
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $order = ($pair['solved'][$middle] <=> $solved) ?: ($pair['tracked'][$middle] <=> $tracked);

            if ($order < 0 || ($inclusive && $order === 0)) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low > 0 ? $low - 1 : null;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function firstMoment(string $playerId): array
    {
        $first = null;

        foreach ($this->rows[$playerId] as $pair) {
            $moment = [$pair['solved'][0], $pair['tracked'][0]];

            if ($first === null || ($moment <=> $first) < 0) {
                $first = $moment;
            }
        }

        assert($first !== null);

        return $first;
    }

    private function sortPlayer(string $playerId): void
    {
        foreach ($this->rows[$playerId] as $piecesCount => $pair) {
            if ($pair['sorted']) {
                continue;
            }

            $order = array_keys($pair['solved']);
            usort($order, static fn (int $a, int $b): int => [$pair['solved'][$a], $pair['tracked'][$a]] <=> [$pair['solved'][$b], $pair['tracked'][$b]]);

            $sorted = ['solved' => [], 'tracked' => [], 'baseline' => [], 'drift' => [], 'spread' => [], 'sorted' => true];

            foreach ($order as $index) {
                $count = count($sorted['solved']);

                // The same moment twice: the later one (the sort is stable) is the pace after both
                if ($count > 0 && $sorted['solved'][$count - 1] === $pair['solved'][$index] && $sorted['tracked'][$count - 1] === $pair['tracked'][$index]) {
                    array_pop($sorted['baseline']);
                    $sorted['baseline'][] = $pair['baseline'][$index];

                    if ($this->withStates) {
                        array_pop($sorted['drift']);
                        array_pop($sorted['spread']);
                        $sorted['drift'][] = $pair['drift'][$index] ?? null;
                        $sorted['spread'][] = $pair['spread'][$index] ?? null;
                    }

                    continue;
                }

                $sorted['solved'][] = $pair['solved'][$index];
                $sorted['tracked'][] = $pair['tracked'][$index];
                $sorted['baseline'][] = $pair['baseline'][$index];

                if ($this->withStates) {
                    $sorted['drift'][] = $pair['drift'][$index] ?? null;
                    $sorted['spread'][] = $pair['spread'][$index] ?? null;
                }
            }

            $this->rows[$playerId][$piecesCount] = $sorted;
        }
    }
}
