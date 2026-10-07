<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A person of a pair/team round entry, as the organiser's participant list has them (GetRoundResultEntries).
 */
readonly final class RoundResultEntryMember implements \JsonSerializable
{
    public function __construct(
        public string $participantId,
        public string $participantRoundId,
        // The organiser's name for the person - their record, also for a linked player
        public string $name,
        // Lower-case country code (CountryCode case name), as the participant list stores it
        public null|string $country,
        public null|string $playerId,
        public null|string $playerCode,
        public null|string $playerName,
    ) {
    }

    /**
     * @return array{participantId: string, participantRoundId: string, name: string, country: null|string, playerId: null|string, playerCode: null|string, playerName: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'participantId' => $this->participantId,
            'participantRoundId' => $this->participantRoundId,
            'name' => $this->name,
            'country' => $this->country,
            'playerId' => $this->playerId,
            'playerCode' => $this->playerCode,
            'playerName' => $this->playerName,
        ];
    }
}
