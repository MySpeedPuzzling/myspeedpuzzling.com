<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * Takes turns with every write to the event's participants (CompetitionParticipantsLock): a category change is refused
 * while the round holds official results, checked under the same lock the results desk records under.
 */
readonly final class EditCompetitionRound implements SerializedByLock
{
    public const array FIELDS = ['name', 'minutesLimit', 'startsAt', 'timezone', 'badgeBackgroundColor', 'badgeTextColor', 'category', 'resultsLink'];

    /**
     * @param list<string> $keepFields
     */
    public function __construct(
        public string $roundId,
        // The round's event, as the caller authorised it - the handler refuses a round of another event
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
        // Refuse (SecretPuzzlesWouldBeRevealed) when the new start reveals secret puzzles - the internal API without
        // "confirmReveal"; the organiser's form asks before it dispatches (SecretRevealPreview)
        public bool $refuseToReveal = false,
        // The organiser's yes, bound to exactly the list they were shown (SecretRevealPreview::hash() - also of an empty
        // list): re-checked after the locks, a different list now is refused (SecretPuzzlesWouldBeRevealed). Null = no
        // check (the caller asked differently)
        public null|string $confirmedRevealHash = null,
        // Fields to keep as the round has them when the handler holds its lock (EditCompetitionRound::FIELDS) - the
        // values given for them are ignored. A partial update (the internal API's PATCH) never writes back a value it
        // read before another change of the round committed.
        public array $keepFields = [],
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
