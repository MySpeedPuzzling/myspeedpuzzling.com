<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * The pace has stayed within ±2 % of today's for a while (at least 20 first tries over 60+ days).
 */
readonly final class PacePlateau
{
    public function __construct(
        public DateTimeImmutable $since,
        public int $firstTries,
        public int $paceSeconds,
    ) {
    }
}
