<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeProgressUnit;
use SpeedPuzzling\Web\Value\BadgeTier;

readonly final class BadgeProgress
{
    public function __construct(
        public BadgeTier $nextTier,
        public int $currentValue,
        public int $targetValue,
        public int $percent,
        /** Counters compare "how many so far" upward; the speed achievements compare times downward. */
        public BadgeProgressUnit $unit = BadgeProgressUnit::Count,
    ) {
    }
}
