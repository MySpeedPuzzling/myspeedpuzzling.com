<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\CloseCallCandidate;
use SpeedPuzzling\Web\Results\Insights\InsightsCloseCall;
use SpeedPuzzling\Web\Results\Insights\InsightsCloseCallsSection;
use SpeedPuzzling\Web\Results\InsightsSoloResult;

/**
 * "Where am I close to a better place?" - per ranked puzzle the gap to the nearest milestone place above you
 * (LeaderboardPosition::RANK_MILESTONES: #1, top 3, top 10, …) and to the very next place, as a share of your best.
 * Follows the puzzle filters only (pieces, brand, difficulty, personality, picture, shelf): a place is about the
 * puzzle, not about when you solved it. Places only, never people.
 */
readonly final class CloseCallsBuilder
{
    /**
     * @param array<string, CloseCallCandidate> $candidates GetCloseCalls::forPlayer()
     * @param array<string, InsightsSoloResult> $puzzles one result per puzzle (its puzzle's attributes), by puzzle id
     */
    public function build(array $candidates, array $puzzles, InsightsCriteria $criteria): InsightsCloseCallsSection
    {
        $items = [];
        $first = 0;
        $podium = 0;
        $topTen = 0;
        $puzzleCount = 0;

        foreach ($candidates as $puzzleId => $candidate) {
            $puzzle = $puzzles[$puzzleId] ?? null;

            // A puzzle without a counted result (secret) or outside the filters
            if ($puzzle === null || $criteria->matchesPuzzle($puzzle) === false) {
                continue;
            }

            $puzzleCount++;
            $first += $candidate->rank === 1 ? 1 : 0;
            $podium += $candidate->rank <= 3 ? 1 : 0;
            $topTen += $candidate->rank <= 10 ? 1 : 0;

            if ($candidate->rank === 1) {
                continue;
            }

            $targetRank = null;
            $targetSeconds = null;

            foreach ($candidate->milestoneSeconds as $position => $seconds) {
                if ($position < $candidate->rank && ($targetRank === null || $position > $targetRank)) {
                    $targetRank = $position;
                    $targetSeconds = $seconds;
                }
            }

            $targetGap = $targetSeconds !== null ? $candidate->bestSeconds - $targetSeconds : null;

            $items[] = new InsightsCloseCall(
                puzzleId: $candidate->puzzleId,
                rank: $candidate->rank,
                bestSeconds: $candidate->bestSeconds,
                targetRank: $targetRank,
                targetGapSeconds: $targetGap,
                targetGapShare: $targetGap !== null ? $targetGap / $candidate->bestSeconds : null,
                nextPlaceGapSeconds: $candidate->nextPlaceSeconds !== null ? $candidate->bestSeconds - $candidate->nextPlaceSeconds : null,
            );
        }

        usort($items, static function (InsightsCloseCall $a, InsightsCloseCall $b): int {
            return [$a->targetGapShare ?? INF, $a->rank, $a->puzzleId] <=> [$b->targetGapShare ?? INF, $b->rank, $b->puzzleId];
        });

        $close = count(array_filter($items, static fn (InsightsCloseCall $item): bool => $item->targetGapShare !== null && $item->targetGapShare < InsightsCloseCallsSection::CLOSE_SHARE));

        return new InsightsCloseCallsSection(
            items: array_slice($items, 0, InsightsCloseCallsSection::LIST_LIMIT),
            closeCount: $close,
            candidateCount: count($items),
            firstPlaceCount: $first,
            podiumCount: $podium,
            topTenCount: $topTen,
            puzzleCount: $puzzleCount,
            notSureYet: $puzzleCount === 0,
        );
    }
}
