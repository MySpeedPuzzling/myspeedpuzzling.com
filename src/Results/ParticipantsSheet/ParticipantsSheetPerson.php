<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

use DateTimeImmutable;

/**
 * One participant of the event as the participants spreadsheet shows it (ParticipantsSheetState) - removed ones too.
 */
readonly final class ParticipantsSheetPerson implements \JsonSerializable
{
    /**
     * @param list<string> $playerResultRounds
     */
    public function __construct(
        public string $id,
        public string $name,
        // CountryCode case name (lower case) or null
        public null|string $country,
        public null|string $externalId,
        // The organiser's private note
        public null|string $note,
        // ParticipantSource value: self_joined | imported | manual
        public string $source,
        public null|DateTimeImmutable $removedAt,
        public null|DateTimeImmutable $connectedAt,
        public null|ParticipantsSheetPlayer $player,
        // null = the event does not manage registration
        public null|ParticipantsSheetRegistration $registration,
        // Rounds of the event in which the linked player has a time of their own (or of their pair/team) - such a
        // person cannot be taken out of that round (the results guard)
        public array $playerResultRounds,
    ) {
    }

    /**
     * @return array{id: string, name: string, country: null|string, externalId: null|string, note: null|string, source: string, removedAt: null|string, connectedAt: null|string, player: null|ParticipantsSheetPlayer, registration: null|ParticipantsSheetRegistration, playerResultRounds: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => $this->country,
            'externalId' => $this->externalId,
            'note' => $this->note,
            'source' => $this->source,
            'removedAt' => $this->removedAt?->format(DateTimeImmutable::ATOM),
            'connectedAt' => $this->connectedAt?->format(DateTimeImmutable::ATOM),
            'player' => $this->player,
            'registration' => $this->registration,
            'playerResultRounds' => $this->playerResultRounds,
        ];
    }
}
