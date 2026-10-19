<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * Every pair/team result of a player, oldest first, with the ids of every registered person in them (for their pace
 * history). The ids are internal to the synergy: a hidden private member has no id in the results themselves.
 */
readonly final class InsightsGroupResults
{
    /**
     * @param list<InsightsGroupResult> $results
     * @param list<string> $memberPlayerIds every registered member of these pairs/teams, the player included
     * @param array<string, list<null|string>> $memberIdsByTime time id => every member's player id in the team's order, null = a guest
     */
    public function __construct(
        public array $results,
        public array $memberPlayerIds,
        public array $memberIdsByTime,
    ) {
    }
}
