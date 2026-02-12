<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PlayerRatingSummary
{
    public function __construct(
        public float $averageRating,
        public int $ratingCount,
    ) {
    }
}
