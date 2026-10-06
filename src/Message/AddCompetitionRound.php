<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RoundCategory;

readonly final class AddCompetitionRound
{
    public function __construct(
        public UuidInterface $roundId,
        public string $competitionId,
        public string $name,
        public int $minutesLimit,
        // The instant (UTC); the organiser typed it as local time in $timezone
        public DateTimeImmutable $startsAt,
        public string $timezone,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public RoundCategory $category = RoundCategory::Solo,
        public null|string $resultsLink = null,
    ) {
    }
}
