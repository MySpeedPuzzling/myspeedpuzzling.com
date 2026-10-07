<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The one lock key (SerializedByLock) of every write to an event's participants, round entries, teams and official
 * results, and of every round change that can delete or invalidate official results (category change, round delete):
 * they take turns per event, so a registration never counts the free spots while another one takes the last of them,
 * an import never plans over a write that is half way through, and a guard's "no official result" check never races a
 * result being recorded. The key is the participant import's own (ApplyParticipantImport), so an import in flight and a
 * registration serialise against each other. SerializedByLockMessagesTest fails for a handler touching that model whose
 * message does not take it (docs/features/competitions-management/registration.md, Concurrency).
 */
final readonly class CompetitionParticipantsLock
{
    public static function key(string $competitionId): string
    {
        return 'participant-import-' . strtolower($competitionId);
    }
}
