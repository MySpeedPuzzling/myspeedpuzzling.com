<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

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
        // Minutes after the start when the round's secret puzzles with an automatic reveal come out (RoundPuzzleReveal)
        public int $revealDelayMinutes = RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
    ) {
    }
}
