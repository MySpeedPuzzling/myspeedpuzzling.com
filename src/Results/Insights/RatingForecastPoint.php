<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

readonly final class RatingForecastPoint
{
    public function __construct(
        public int $monthsAhead,
        public DateTimeImmutable $date,
        /** Ladder points (× 1000) */
        public float $rating,
        /** Whether the player would still / already be on the ladder then */
        public bool $onLadder,
    ) {
    }
}
