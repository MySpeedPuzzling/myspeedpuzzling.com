<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "I'm going" - or, on an event that manages registration, a registration (reserved or waitlisted by the free spots).
 * Takes turns with every other write to the event's participants, so two registrations never take the last spot both.
 */
readonly final class JoinCompetition implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
        public null|string $participantId = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
