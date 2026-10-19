<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "Where am I close to a better place?" (free). items = the closest LIST_LIMIT by share of your time; closeCount =
 * puzzles under CLOSE_SHARE away from their next milestone place.
 */
readonly final class InsightsCloseCallsSection
{
    public const int LIST_LIMIT = 8;

    public const float CLOSE_SHARE = 0.03;

    /**
     * @param list<InsightsCloseCall> $items
     */
    public function __construct(
        public array $items,
        public int $closeCount,
        public int $candidateCount,
        public int $firstPlaceCount,
        public int $podiumCount,
        public int $topTenCount,
        public int $puzzleCount,
        public bool $notSureYet,
    ) {
    }
}
