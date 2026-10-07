<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Moves one round entry into a pair/team of its round (or out of every one) - takes turns with the results desk, which
 * reads the line-ups.
 */
readonly final class AssignParticipantToTeam implements SerializedByLock
{
    public function __construct(
        // The event the organiser was authorised for - the round entry must be one of its own
        public string $competitionId,
        public string $participantRoundId,
        public null|string $teamId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
