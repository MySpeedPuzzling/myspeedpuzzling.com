<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

readonly final class RatingOverTimeSeries
{
    /**
     * @param list<RatingDay> $days oldest first
     */
    public function __construct(
        public int $piecesCount,
        public array $days,
        /** The latest rating against the last day at least 30 / 90 days before it; null without such a day */
        public null|float $changeOver30Days,
        public null|float $changeOver90Days,
        public null|RatingDay $biggestRise,
        public null|RatingDay $biggestDrop,
    ) {
    }
}
