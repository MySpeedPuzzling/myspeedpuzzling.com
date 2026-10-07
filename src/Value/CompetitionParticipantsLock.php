<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The one lock key (SerializedByLock) of every write to an event's participants, round entries, teams and official
 * results: they take turns per event, so a registration never counts the free spots while another one takes the last
 * of them, and an import never plans over a write that is half way through. The key is the participant import's own
 * (ApplyParticipantImport), so an import in flight and a registration serialise against each other.
 */
final readonly class CompetitionParticipantsLock
{
    public static function key(string $competitionId): string
    {
        return 'participant-import-' . strtolower($competitionId);
    }
}
