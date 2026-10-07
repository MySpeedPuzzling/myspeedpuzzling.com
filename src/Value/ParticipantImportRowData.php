<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One row of an uploaded participant list after the column mapping - what the planner works with.
 * Every value is the trimmed cell; null means "no column mapped for it", '' means "mapped, but the cell is empty".
 */
readonly final class ParticipantImportRowData
{
    /**
     * @param array<string, string> $teamsByRound round id => cell of that round's own team column (key present = mapped)
     */
    public function __construct(
        public int $rowNumber,
        /** '' when the name cells are empty - such a row is reported, never imported */
        public string $name,
        public null|string $country = null,
        public null|string $externalId = null,
        public null|string $playerId = null,
        public null|string $participantId = null,
        public null|string $status = null,
        /** The `round_names` list cell, split by the planner (a round name may contain a comma) */
        public null|string $roundNames = null,
        /** The single `round_name` cell */
        public null|string $roundName = null,
        /** The generic team cell - the team in every pair/team round of the row */
        public null|string $team = null,
        public array $teamsByRound = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rowNumber' => $this->rowNumber,
            'name' => $this->name,
            'country' => $this->country,
            'externalId' => $this->externalId,
            'playerId' => $this->playerId,
            'participantId' => $this->participantId,
            'status' => $this->status,
            'roundNames' => $this->roundNames,
            'roundName' => $this->roundName,
            'team' => $this->team,
            'teamsByRound' => $this->teamsByRound,
        ];
    }
}
