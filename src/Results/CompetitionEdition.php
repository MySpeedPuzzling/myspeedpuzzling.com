<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class CompetitionEdition
{
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $startsAt,
        public int $minutesLimit,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public int $puzzleCount,
    ) {
    }
}
