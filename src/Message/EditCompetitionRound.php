<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

readonly final class EditCompetitionRound
{
    public const array FIELDS = ['name', 'minutesLimit', 'startsAt', 'timezone', 'badgeBackgroundColor', 'badgeTextColor', 'category', 'resultsLink'];

    /**
     * @param list<string> $keepFields
     */
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
        // Refuse (SecretPuzzlesWouldBeRevealed) when the change moves the round's automatic reveal (start + reveal delay)
        // earlier for secret puzzles - by default, so no caller reveals anything early without asking. The internal API
        // passes !confirmReveal; the organiser's form asks before it dispatches (SecretRevealPreview) and passes false
        // together with $confirmedRevealHash
        public bool $refuseToReveal = true,
        // The organiser's yes, bound to exactly the list they were shown (SecretRevealPreview::hash() - also of an empty
        // list): re-checked after the locks, a different list now is refused (SecretPuzzlesWouldBeRevealed). Null = no
        // check (the caller asked differently)
        public null|string $confirmedRevealHash = null,
        // Fields to keep as the round has them when the handler holds its lock (EditCompetitionRound::FIELDS) - the
        // values given for them are ignored. A partial update (the internal API's PATCH) never writes back a value it
        // read before another change of the round committed.
        public array $keepFields = [],
        // Minutes after the start when the round's secret puzzles with an automatic reveal come out (RoundPuzzleReveal).
        // Null = keep the round's value as it is under the handler's lock (callers that do not set it)
        public null|int $revealDelayMinutes = null,
    ) {
    }
}
