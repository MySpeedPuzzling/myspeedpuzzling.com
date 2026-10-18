<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

readonly final class PacePoint
{
    public function __construct(
        public DateTimeImmutable $date,
        /** Qualifying first attempts so far at the piece count */
        public int $firstTries,
        public int $paceSeconds,
    ) {
    }
}
