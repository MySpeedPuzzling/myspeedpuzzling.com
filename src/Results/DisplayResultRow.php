<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One ranked official result as a competition display shows it (D-displays-overlays.md §5.1, GetCompetitionDisplayState):
 * the organiser's participant / team name only - masked exactly as the published round page masks for a guest (no
 * profile name, #code, avatar or id, a private player's own country never) - and an opaque key per display for DOM
 * diffing, never an entry or participant id.
 */
readonly final class DisplayResultRow implements \JsonSerializable
{
    /**
     * @param list<string> $members a pair/team's member names (organiser's), empty for a person
     * @param list<string> $countries lowercase country codes, each once
     */
    public function __construct(
        public string $key,
        public null|int $rank,
        public string $name,
        public array $members,
        public array $countries,
        public null|int $resultSeconds,
        public null|int $piecesPlaced,
        public bool $didNotFinish,
        public null|int $tableNumber,
        public null|int $enteredAtMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'rank' => $this->rank,
            'name' => $this->name,
            'members' => $this->members,
            'countries' => $this->countries,
            'resultSeconds' => $this->resultSeconds,
            'piecesPlaced' => $this->piecesPlaced,
            'didNotFinish' => $this->didNotFinish,
            'tableNumber' => $this->tableNumber,
            'enteredAtMs' => $this->enteredAtMs,
        ];
    }
}
