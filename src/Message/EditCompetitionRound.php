<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

readonly final class EditCompetitionRound
{
    public function __construct(
        public string $roundId,
        public string $name,
        public int $minutesLimit,
        // The instant (UTC); the organiser typed it as local time in $timezone
        public DateTimeImmutable $startsAt,
        public string $timezone,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public RoundCategory $category = RoundCategory::Solo,
        public null|string $resultsLink = null,
        // Refuse (SecretPuzzlesWouldBeRevealed) when the new start reveals secret puzzles - the internal API without
        // "confirmReveal"; the organiser's form asks before it dispatches (SecretRevealPreview)
        public bool $refuseToReveal = false,
    ) {
    }
}
