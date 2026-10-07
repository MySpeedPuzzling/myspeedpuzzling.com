<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Everything on the site the file does not have (docs/features/competitions-management/participant-import-preview.md D7,
 * D10-D16). Full sync removes it; "Update only" keeps it - the preview shows the list in both modes.
 */
readonly final class ParticipantImportRemovals
{
    /**
     * @param list<array{participantId: string, name: string, country: null|string, playerName: null|string}> $participants
     *        organiser's participants (imported/manual) not in the file - soft-deleted by full sync
     * @param list<array{participantId: string, name: string, country: null|string, playerName: null|string}> $selfJoined
     *        players who joined on MySpeedPuzzling by themselves and are not in the file - soft-deleted by full sync too,
     *        listed apart because the organiser may not know about them
     * @param list<array{participantId: string, name: string, rounds: list<string>}> $participantsKeptWithResults
     *        not in the file, but with results in the event - never removed
     * @param list<array{entryId: string, participantName: string, roundName: string, teamName: null|string}> $roundEntries
     *        a person of the file taken out of a round (only when a Rounds column is mapped)
     * @param list<array{entryId: string, participantName: string, roundName: string}> $roundEntriesKeptWithResults
     * @param list<array{participantName: string, roundName: string, from: null|string, to: null|string}> $teamChanges
     *        full sync moves a person to the file's team in a round that has its own team column (null = no team / unnamed)
     * @param list<array{teamId: string, roundName: string, teamName: null|string, activeMembers: int}> $teams
     *        pairs/teams full sync deletes: they end up with no active member (members unassigned first)
     */
    public function __construct(
        public array $participants = [],
        public array $selfJoined = [],
        public array $participantsKeptWithResults = [],
        public array $roundEntries = [],
        public array $roundEntriesKeptWithResults = [],
        public array $teamChanges = [],
        public array $teams = [],
    ) {
    }

    public function count(): int
    {
        return count($this->participants) + count($this->selfJoined) + count($this->roundEntries) + count($this->teams);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0 && $this->teamChanges === [];
    }
}
