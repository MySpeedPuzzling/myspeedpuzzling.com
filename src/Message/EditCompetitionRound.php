<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

readonly final class EditCompetitionRound
{
    public function __construct(
        public string $roundId,
        public string $name,
        public int $minutesLimit,
        public DateTimeImmutable $startsAt,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
    ) {
    }
}
