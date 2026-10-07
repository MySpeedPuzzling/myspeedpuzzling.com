<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * An entrant typed in at the venue (quick add) - created with the device's own id, so a resent change finds the
 * entry it created and never creates it twice. Never matched to an existing participant by name: the organiser's
 * tools show existing entrants first.
 * - person (solo round): a new participant of the event, in this round - the round entry's id is `clientEntryId`
 * - team (pair/team round): a new team of this round with id `clientEntryId`, each member a new participant in it
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
        /** @var list<array{name: string, country: null|string}> team members */
        public array $members = [],
    ) {
    }

    public function ref(): RoundEntryRef
    {
        return $this->kind === self::KIND_TEAM
            ? RoundEntryRef::team($this->clientEntryId)
            : RoundEntryRef::participantRound($this->clientEntryId);
    }
}
