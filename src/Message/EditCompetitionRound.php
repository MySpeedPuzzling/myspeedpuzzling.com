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
        // The organiser's yes, bound to exactly the list they were shown (SecretRevealPreview::hash() - also of an empty
        // list): re-checked after the locks, a different list now is refused (SecretPuzzlesWouldBeRevealed). Null = no
        // check (the caller asked differently)
        public null|string $confirmedRevealHash = null,
    ) {
    }
}
