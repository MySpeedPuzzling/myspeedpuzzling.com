<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * One close call: your place, the next milestone place above it (LeaderboardPosition::RANK_MILESTONES) and the
 * seconds a re-solve needs to reach it ("#12 · 4 s from top 10"), plus the gap to the very next place
 * ("4 s from #11"). Places only - nobody is named.
 */
readonly final class InsightsCloseCall
{
    public function __construct(
        public string $puzzleId,
        public int $rank,
        public int $bestSeconds,
        public null|int $targetRank,
        public null|int $targetGapSeconds,
        public null|float $targetGapShare,
        public null|int $nextPlaceGapSeconds,
    ) {
    }
}
