<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

readonly final class RatingOverTimeResult
{
    /**
     * @param list<RatingOverTimeSeries> $series one per rated piece count with snapshots
     */
    public function __construct(
        public array $series,
    ) {
    }
}
