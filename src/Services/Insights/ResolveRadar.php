<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetInsightsResolveCandidates;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Results\Insights\ResolveCandidate;
use SpeedPuzzling\Web\Results\Insights\ResolveRadarResult;
use SpeedPuzzling\Web\Value\PiecesRange;

/**
 * "What should I re-solve?" (docs/features/insights/computation.md §4c.4, the prototype's renderResolve()): puzzles
 * last solved at least MINIMUM_MONTHS ago whose next attempt is likely at least MINIMUM_GAIN faster than the best -
 * the personal predictions of every solved puzzle in bulk (GetPlayerPredictions, list scale, never one per puzzle).
 * Four statements whatever the history size.
 */
readonly final class ResolveRadar
{
    public const int MINIMUM_MONTHS = 12;
    public const float MINIMUM_GAIN = 0.05;

    public function __construct(
        private GetInsightsResolveCandidates $getInsightsResolveCandidates,
        private GetPlayerPredictions $getPlayerPredictions,
        private ClockInterface $clock,
    ) {
    }

    public function build(string $playerId, null|PiecesRange $pieces = null): ResolveRadarResult
    {
        $now = $this->clock->now();
        $rows = $this->getInsightsResolveCandidates->lastSolvedBefore($playerId, $now->modify('-' . self::MINIMUM_MONTHS . ' months'), $now);

        if ($rows === []) {
            return new ResolveRadarResult([], 0);
        }

        $predictions = $this->getPlayerPredictions->forAllSolvedPuzzles($playerId);
        $candidates = [];

        foreach ($rows as $row) {
            if ($pieces !== null && $pieces->contains($row['puzzle']->piecesCount) === false) {
                continue;
            }

            $prediction = $predictions[$row['puzzle']->puzzleId] ?? null;

            if ($prediction === null || $row['best_seconds'] <= 0) {
                continue;
            }

            $gain = 1.0 - $prediction->predictedSeconds / $row['best_seconds'];

            if ($gain < self::MINIMUM_GAIN) {
                continue;
            }

            $candidates[] = new ResolveCandidate(
                puzzle: $row['puzzle'],
                bestSeconds: $row['best_seconds'],
                lastSolvedAt: $row['last_solved_at'],
                monthsSinceLastSolve: $row['last_solved_at']->diff($now)->days / 30.44,
                attempts: $row['attempts'],
                predictedSeconds: $prediction->predictedSeconds,
                predictedLowSeconds: $prediction->rangeLowSeconds,
                predictedHighSeconds: $prediction->rangeHighSeconds,
                likelyGain: $gain,
                onShelf: $row['on_shelf'],
                soloResultsCount: $row['solo_count'],
            );
        }

        usort($candidates, static fn (ResolveCandidate $a, ResolveCandidate $b): int => $b->likelyGain <=> $a->likelyGain ?: strcmp($a->puzzle->puzzleId, $b->puzzle->puzzleId));

        return new ResolveRadarResult(
            $candidates,
            count(array_filter($candidates, static fn (ResolveCandidate $candidate): bool => $candidate->onShelf)),
        );
    }
}
