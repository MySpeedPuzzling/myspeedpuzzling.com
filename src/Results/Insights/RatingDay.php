<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * One day of player_rating_snapshot (the day's last values).
 */
readonly final class RatingDay
{
    public function __construct(
        public DateTimeImmutable $date,
        /** Ladder points (× 1000) */
        public float $rating,
        /** The place stored with the snapshot (public players rated at least as high) */
        public null|int $place,
        /** Against the previous stored day; null on the first one */
        public null|float $change,
        /** Days since the previous stored day (snapshots can skip days) */
        public null|int $daysSincePrevious,
    ) {
    }
}
