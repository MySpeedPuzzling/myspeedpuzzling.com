<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "500s under 55:00": the next round time (5-minute steps) below the player's pace, and how often they beat it.
 */
readonly final class TargetTimeMilestone
{
    public function __construct(
        public int $piecesCount,
        public int $targetSeconds,
        /** Solo boxed results at the piece count in the last 91 days, and those under the target */
        public int $results,
        public int $under,
        /** The share 6 months on if the times follow the pace trend; null without a trend */
        public null|float $projectedShare,
    ) {
    }

    public function share(): float
    {
        return $this->results > 0 ? $this->under / $this->results : 0.0;
    }
}
