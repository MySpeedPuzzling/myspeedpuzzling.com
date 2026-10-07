<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

/**
 * What ParticipantImportApplier writes - made by the planner, opaque to everybody else (ParticipantImportPlan::$operations).
 * New participants are referenced by "new:<row number>", new teams by "n:<round id>:<team name key>", existing teams
 * by "t:<team id>": nothing here is generated at plan time, so the same file on the same data plans the same.
 */
readonly final class ParticipantImportOperations
{
    /**
     * @param list<array{key: string, name: string, country: null|string, externalId: null|string, connectPlayerId: null|string, markAsImported: bool, restore: bool, softDelete: bool, changed: bool}> $participants
     *        created (key "new:…") or changed (key = participant id) participants; values are the state after the import,
     *        `changed` = a value the organiser sees changes (not only markAsImported())
     * @param list<array{key: string, roundId: string, name: string}> $newTeams
     * @param list<array{participantKey: string, roundId: string, team: null|string}> $newEntries
     * @param list<array{entryId: string, team: null|string}> $entryTeams an existing entry gets another team (null = none)
     * @param list<string> $deletedEntries entry ids (full sync)
     * @param list<string> $deletedTeams team ids (full sync - teams the import empties)
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
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->participants === []
            && $this->newTeams === []
            && $this->newEntries === []
            && $this->entryTeams === []
            && $this->deletedEntries === []
            && $this->deletedTeams === [];
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
            && $this->deletedTeams === [];
    }
}
