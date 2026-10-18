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
 * 2. else interpolated/extrapolated from the rows valid just before it at the solver's other piece
 *    counts (PlayerBaselineCalculator::gapBaseline(), the rule of the baselines' second pass);
 * 3. else - the solve came before the solver had any pace (one of their first first attempts) - their
 *    first pace: 1 + 2 at the moment of their first row, when that row is known at the moment the
 *    difficulty is computed for ($knownBefore; the batch knows every row).
 */
final class PaceTimeline
{
    /** @var array<string, array<int, array{solved: list<float>, tracked: list<float>, baseline: list<int>, sorted: bool}>> player => piece count => rows ascending */
    private array $rows = [];

    public function __construct(
        private readonly PlayerBaselineCalculator $baselineCalculator,
        private null|float $scalingExponent = null,
    ) {
    }

    public function add(string $playerId, int $piecesCount, float $solved, float $tracked, int $baselineSeconds): void
    {
        if (!isset($this->rows[$playerId][$piecesCount])) {
            $this->rows[$playerId][$piecesCount] = ['solved' => [], 'tracked' => [], 'baseline' => [], 'sorted' => true];
        }

        $pair = &$this->rows[$playerId][$piecesCount];

        $last = count($pair['solved']) - 1;

        if ($last >= 0) {
            $order = ($solved <=> $pair['solved'][$last]) ?: ($tracked <=> $pair['tracked'][$last]);

            if ($order === 0) {
                return; // the same row twice (several lookups loaded it)
            }

            if ($order < 0) {
                $pair['sorted'] = false;
            }
        }

        $pair['solved'][] = $solved;
        $pair['tracked'][] = $tracked;
        $pair['baseline'][] = $baselineSeconds;
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

            if ($baseline !== null) {
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
     * @param array{solved: list<float>, tracked: list<float>, baseline: list<int>, sorted: bool} $pair
     */
    private function latest(array $pair, float $solved, float $tracked, bool $inclusive): null|int
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

        return $low > 0 ? $pair['baseline'][$low - 1] : null;
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

            $sorted = ['solved' => [], 'tracked' => [], 'baseline' => [], 'sorted' => true];

            foreach ($order as $index) {
                $count = count($sorted['solved']);

                if ($count > 0 && $sorted['solved'][$count - 1] === $pair['solved'][$index] && $sorted['tracked'][$count - 1] === $pair['tracked'][$index]) {
                    continue;
                }

                $sorted['solved'][] = $pair['solved'][$index];
                $sorted['tracked'][] = $pair['tracked'][$index];
                $sorted['baseline'][] = $pair['baseline'][$index];
            }

            $this->rows[$playerId][$piecesCount] = $sorted;
        }
    }
}
