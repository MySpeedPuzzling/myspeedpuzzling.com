<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * Every pair/team result of a player, oldest first, with the ids of every registered person in them (for their pace
 * history).
 */
readonly final class InsightsGroupResults
{
    /**
     * @param list<InsightsGroupResult> $results
     * @param list<string> $memberPlayerIds every registered member of these pairs/teams, the player included
     */
    public function __construct(
        public array $results,
        public array $memberPlayerIds,
    ) {
    }
}
