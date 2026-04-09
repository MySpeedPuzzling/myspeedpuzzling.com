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
        public DateTimeImmutable $startsAt,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public RoundCategory $category = RoundCategory::Solo,
    ) {
    }
}
