<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\SheetChangeGroup;

/**
 * A change set of the participants sheet (docs/features/competitions-management/participants-spreadsheet.md §6) -
 * groups of field-level, three-way checked changes, each group all or nothing. Takes turns with every other write to the
 * event's participants, entries, pairs/teams and results (CompetitionParticipantsLock): the plan reads the event under
 * the lock, so nothing changes between the check and the write. A dry run plans the same and writes nothing (the
 * paste and bulk previews). Handled by ApplyParticipantSheetChangesHandler → AppliedParticipantSheetChanges.
 */
readonly final class ApplyParticipantSheetChanges implements SerializedByLock
{
    /**
     * @param list<SheetChangeGroup> $groups
     */
    public function __construct(
        // The event the caller was authorised on (COMPETITION_EDIT) - every participant, round and team of the changes
        // must be one of its
        public string $competitionId,
        public string $actingPlayerId,
        // The page's id of the change set: a resend with the same id is answered from its receipt, never applied again.
        // Null only on a dry run
        public null|string $changesetId,
        public array $groups,
        public bool $dryRun = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
