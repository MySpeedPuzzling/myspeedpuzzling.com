<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\ShrunkEffect;
use SpeedPuzzling\Web\Value\SynergyVerdict;

/**
 * One pair or team the player solves with (one puzzling_team = the exact set of people) and how they do together.
 */
readonly final class GroupSynergy
{
    /**
     * @param list<GroupResultMember> $partners everybody but the player, in the team's order
     */
    public function __construct(
        public string $teamId,
        public null|string $teamName,
        public int $size,
        public array $partners,
        public int $resultsCount,
        /** Results with a score: no guest, every member with a pace, a rated puzzle, boxed */
        public int $scoredCount,
        /** Shrunk effect of actual ÷ expected with its range; null below 5 scored results */
        public null|ShrunkEffect $effect,
        public SynergyVerdict $verdict,
        /** Median of (your expected solo time ÷ the time together): 1.8 = together 1.8× as fast as you alone; null without your pace */
        public null|float $speedVsAlone,
    ) {
    }
}
