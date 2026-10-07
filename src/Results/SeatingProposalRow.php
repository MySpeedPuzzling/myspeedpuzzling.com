<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One entrant of a seating proposal (SeatingProposer): the table it would get. `basis*` say which earlier round's
 * result placed it (the organiser's own record) - MySpeedPuzzling times never travel, only the order they gave.
 */
readonly final class SeatingProposalRow implements \JsonSerializable
{
    public function __construct(
        public string $entryRef,
        public string $displayName,
        public int $tableNumber,
        public null|int $currentTableNumber,
        // False = placed after the entrants with data, by name
        public bool $hasData,
        public null|string $basisRoundId = null,
        public null|string $basisRoundName = null,
        public null|int $basisRank = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'entry' => $this->entryRef,
            'displayName' => $this->displayName,
            'tableNumber' => $this->tableNumber,
            'currentTableNumber' => $this->currentTableNumber,
            'hasData' => $this->hasData,
            'basis' => $this->basisRoundId === null ? null : [
                'roundId' => $this->basisRoundId,
                'roundName' => $this->basisRoundName,
                'rank' => $this->basisRank,
            ],
        ];
    }
}
