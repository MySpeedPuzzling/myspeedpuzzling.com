<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use Closure;

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
        // The linked player's own private profile setting - never serialized, see forReferee()
        public bool $playerIsPrivate = false,
        // forReferee() left the linked player out
        public bool $playerWithheld = false,
    ) {
    }

    /**
     * The member as a referee may see them (RoundResultEntry::forReferee()).
     *
     * @param Closure(string): bool $isRevealed
     */
    public function forReferee(Closure $isRevealed): self
    {
        if ($this->playerId === null || $this->playerIsPrivate === false || $isRevealed($this->playerId)) {
            return $this;
        }

        return new self(
            participantId: $this->participantId,
            participantRoundId: $this->participantRoundId,
            name: $this->name,
            country: $this->country,
            playerId: null,
            playerCode: null,
            playerName: null,
            playerIsPrivate: true,
            playerWithheld: true,
        );
    }

    /**
     * @return array<string, null|string|bool>
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
            // Only on what a referee gets - the organisers' JSON stays as it was
            ...($this->playerWithheld ? ['playerWithheld' => true] : []),
        ];
    }
}
