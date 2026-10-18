<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "Who do I solve best with?" - pairs and teams separately (like with like, never mixed with solo).
 */
readonly final class PairsTeamsSynergyResult
{
    /**
     * @param list<GroupSynergy> $pairs best match first, unscored ones last (by results)
     * @param list<GroupSynergy> $teams teams of 3+, the same order
     * @param list<GroupResultScore> $bestPairResults up to 5, most under expected first
     * @param list<GroupResultScore> $bestTeamResults
     */
    public function __construct(
        public int $pairResultsCount,
        public int $teamResultsCount,
        public array $pairs,
        public array $teams,
        public array $bestPairResults,
        public array $bestTeamResults,
    ) {
    }
}
