<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\RatingForecastScenario;

/**
 * The next milestone place on the ladder (LeaderboardPosition::RANK_MILESTONES) and what it takes - against today's
 * ladder (everybody else's ratings move too).
 */
readonly final class LadderMilestone
{
    public function __construct(
        public int $place,
        public int $targetPlace,
        /** Ladder points the target place takes today, and the gap from the player's rating */
        public float $ratingNeeded,
        public float $gap,
        /** The first scenario whose 6-month rating gets there (keep pace before best moves), null = none */
        public null|RatingForecastScenario $reachedBy,
    ) {
    }
}
