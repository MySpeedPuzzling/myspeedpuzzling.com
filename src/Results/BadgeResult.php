<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class BadgeResult
{
    public function __construct(
        public BadgeType $type,
        public null|BadgeTier $tier,
        public DateTimeImmutable $earnedAt,
    ) {
    }
}
