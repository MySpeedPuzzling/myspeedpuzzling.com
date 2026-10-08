<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Moves a round to another one-time event or edition (docs/features/organizations/README.md "Restructuring tools", D7):
 * the round with its puzzles (and their reveal), its table layout and every solving time that belongs to it. Refused
 * (409, nothing changes) for the same competition, a round with participants entered, a running stopwatch, or a puzzle
 * of the round already in a round of the same category in the target. The caller checks the actor may edit both
 * competitions.
 *
 * Under the participants lock of the competition the round is in (`competitionId`, also checked by the handler: the
 * round must still be there) - a round entry is never written while the round leaves.
 */
readonly final class MoveRoundToCompetition implements SerializedByLock
{
    public function __construct(
        public string $roundId,
        // The competition the round is in now - the lock key
        public string $competitionId,
        public string $targetCompetitionId,
        public string $actingPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
