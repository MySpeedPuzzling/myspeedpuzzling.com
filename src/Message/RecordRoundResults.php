<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\RoundResultChange;

/**
 * The one write path of official results (docs/features/competitions-management/official-results.md): results,
 * table numbers and qualified marks of a round's entries, plus entrants typed in at the venue. Every change is checked
 * three-way against the value the device last saw; the handler answers with one outcome per change
 * (RecordedRoundResults, from the HandledStamp). A dry run checks and answers without writing.
 *
 * `competitionId` is the competition the caller authorised (CompetitionEditVoter) - a round of another one is refused.
 * Serialised with every other participant write of the event (the participant import, registrations, ...).
 */
readonly final class RecordRoundResults implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        public string $actingPlayerId,
        /** @var list<RoundResultChange> */
        public array $changes,
        public bool $dryRun = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
