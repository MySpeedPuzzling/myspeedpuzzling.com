<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\RatingStanding;

/**
 * "Where is my rating going?" - three scenarios over the next months and the milestones near the player.
 */
readonly final class RatingForecastResult
{
    /**
     * @param list<RatingForecastLine> $lines stop, keep pace, best moves
     */
    public function __construct(
        public int $piecesCount,
        public RatingStanding $standing,
        /** Rated first tries a month over the last 6 months - what "keep your pace" repeats */
        public float $firstTriesPerMonth,
        public array $lines,
        public int $entriesLeavingWithin60Days,
        public null|LadderMilestone $ladder,
        public null|PaceMilestone $pace,
        public null|ResultsMilestone $results,
        public null|TargetTimeMilestone $targetTime,
    ) {
    }
}
