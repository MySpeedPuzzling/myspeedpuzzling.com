<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * An entrant added at the venue (quick add) - created with the device's own id, so a resent change finds the
 * entry it created and never creates it twice. Never matched to an existing participant by name: the organiser's
 * tools show existing entrants first - the round's entries, then the event's people not in the round, who are put
 * in by their id (`participantId`) instead of being typed in again.
 * - person (solo round): `participantId` = a participant of the event put into this round, else a new participant
 *   named `name` - the round entry's id is `clientEntryId`
 * - team (pair/team round): a new team of this round with id `clientEntryId`; each member is a participant of the
 *   event (`participantId`) or a new participant typed in by name
 */
final readonly class NewRoundEntry
{
    public const string KIND_PERSON = 'person';
    public const string KIND_TEAM = 'team';

    public function __construct(
        public string $clientEntryId,
        /** self::KIND_PERSON | self::KIND_TEAM */
        public string $kind,
        // Person: required; team: optional (an unnamed team needs a member)
        public null|string $name,
        // Person only: lower-case country code (CountryCode case name)
        public null|string $country = null,
        /** @var list<array{name: null|string, country: null|string, participantId: null|string}> team members - an existing participant by id, else a new one by name */
        public array $members = [],
        // Person only: an existing participant of the event put into the round (name and country are theirs then)
        public null|string $participantId = null,
    ) {
    }

    /**
     * The existing participants this entry puts into the round - the person, or the pair's/team's members.
     *
     * @return list<string>
     */
    public function existingParticipantIds(): array
    {
        if ($this->kind === self::KIND_PERSON) {
            return $this->participantId !== null ? [$this->participantId] : [];
        }

        return array_values(array_filter(array_column($this->members, 'participantId'), static fn (null|string $id): bool => $id !== null));
    }

    public function ref(): RoundEntryRef
    {
        return $this->kind === self::KIND_TEAM
            ? RoundEntryRef::team($this->clientEntryId)
            : RoundEntryRef::participantRound($this->clientEntryId);
    }
}
