<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Removes a standing (competition toolkit A6) - only the standing, its rounds and results stay as they are.
 */
readonly final class DeleteCompetitionStanding implements SerializedByLock
{
    public function __construct(
        public string $standingId,
        // The standing's event, as the caller authorised it - the handler refuses a standing of another event
        public string $competitionId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
