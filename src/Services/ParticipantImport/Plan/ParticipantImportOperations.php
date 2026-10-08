<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

/**
 * What ParticipantImportApplier writes - made by the import's planner (ParticipantImportPlan::$operations) or the
 * participants sheet's (SheetChangesPlanner), opaque to everybody else.
 *
 * The import references new participants by "new:<row number>", new teams by "n:<round id>:<team name key>" and
 * existing teams by "t:<team id>": nothing there is generated at plan time, so the same file on the same data plans the
 * same. The sheet's new rows come with the page's own ids (`id`, keys "new:<id>" / "n:<id>"), so a change set sent
 * twice can never create anything twice.
 *
 * The optional keys exist for the sheet only - the import never sets them, so its writes stay as they were:
 * `id` / `source` of a new participant, `reserve` (a new participant of an event managing registration holds a spot -
 * reserved, registered now), `disconnect` (the player link cleared), `organizerNote` (present = set to that value, null
 * clears), `leaveWaitlist` (a restored row of an event that does not manage registration), `id` of a new team.
 */
readonly final class ParticipantImportOperations
{
    /**
     * @param list<array{key: string, name: string, country: null|string, externalId: null|string, connectPlayerId: null|string, markAsImported: bool, restore: bool, softDelete: bool, changed: bool, id?: string, source?: string, reserve?: bool, disconnect?: bool, organizerNote?: null|string, leaveWaitlist?: bool}> $participants
     *        created (key "new:…") or changed (key = participant id) participants; values are the state after the write,
     *        `changed` = a value the organiser sees changes (not only markAsImported())
     * @param list<array{key: string, roundId: string, name: null|string, id?: string}> $newTeams
     * @param list<array{participantKey: string, roundId: string, team: null|string}> $newEntries
     * @param list<array{entryId: string, team: null|string}> $entryTeams an existing entry gets another team (null = none)
     * @param list<string> $deletedEntries entry ids (full sync, a person taken out of a round in the sheet)
     * @param list<string> $deletedTeams team ids (full sync - teams the import empties; the sheet's deleted pairs/teams)
     * @param list<array{teamId: string, name: null|string}> $renamedTeams existing teams with another name (the sheet)
     */
    public function __construct(
        public array $participants = [],
        public array $newTeams = [],
        public array $newEntries = [],
        public array $entryTeams = [],
        public array $deletedEntries = [],
        public array $deletedTeams = [],
        public int $added = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $softDeleted = 0,
        public int $restored = 0,
        public int $removed = 0,
        public array $renamedTeams = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->participants === []
            && $this->newTeams === []
            && $this->newEntries === []
            && $this->entryTeams === []
            && $this->deletedEntries === []
            && $this->deletedTeams === []
            && $this->renamedTeams === [];
    }

    /**
     * Nothing the organiser would see: at most self-joined players found on their list become theirs (markAsImported(),
     * bookkeeping - an export imported back does that and nothing else).
     */
    public function changesNothingVisible(): bool
    {
        foreach ($this->participants as $participant) {
            if ($participant['changed']) {
                return false;
            }
        }

        return $this->newTeams === []
            && $this->newEntries === []
            && $this->entryTeams === []
            && $this->deletedEntries === []
            && $this->deletedTeams === []
            && $this->renamedTeams === [];
    }
}
