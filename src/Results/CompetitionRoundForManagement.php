<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

readonly final class CompetitionRoundForManagement
{
    public function __construct(
        public string $id,
        public string $name,
        public int $minutesLimit,
        public DateTimeImmutable $startsAt,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public int $puzzleCount,
        public RoundCategory $category = RoundCategory::Solo,
    ) {
    }
}
