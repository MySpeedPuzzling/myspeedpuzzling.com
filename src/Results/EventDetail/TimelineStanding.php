<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * A published standing linked above the rounds of the event or edition page (competition toolkit A7).
 */
readonly final class TimelineStanding
{
    public function __construct(
        public string $name,
        public RoundCategory $category,
        public string $url,
    ) {
    }
}
