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
     * @param array<string, string> $divisionsByRound round id => cell of that round's own division column (key present =
     *        mapped, shared-puzzles-and-divisions.md §3.5)
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
        /** The organiser's address of the person - an empty cell never clears one (participant-linking.md §7.3) */
        public null|string $email = null,
        /** The Division cell - the division in every round of the row that uses it; null = no column mapped */
        public null|string $division = null,
        public array $divisionsByRound = [],
        /** A team row of the file (member columns mapped): its row number as the organiser sees it - null = a row per person */
        public null|int $teamRow = null,
        /** The member column of the team row this person comes from (1 = the first member column) */
        public null|int $member = null,
        /** The Ticket type cell; null = no column mapped */
        public null|string $ticketType = null,
        /** The round the organiser chose for the ticket type (ColumnMapping::TICKET_SKIP = not imported), null = none chosen */
        public null|string $ticketRound = null,
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
            'email' => $this->email,
            // Only when mapped: an event without divisions hashes its rows exactly as before
            ...($this->division !== null ? ['division' => $this->division] : []),
            ...($this->divisionsByRound !== [] ? ['divisionsByRound' => $this->divisionsByRound] : []),
            // Only for a file of team rows / with a ticket type column: every other file hashes exactly as before
            ...($this->teamRow !== null ? ['teamRow' => $this->teamRow, 'member' => $this->member] : []),
            ...($this->ticketType !== null ? ['ticketType' => $this->ticketType, 'ticketRound' => $this->ticketRound] : []),
        ];
    }
}
