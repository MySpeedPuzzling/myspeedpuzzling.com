<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeTier;

readonly final class BadgeProgress
{
    public function __construct(
        public BadgeTier $nextTier,
        public int $currentValue,
        public int $targetValue,
        public int $percent,
    ) {
    }
}
