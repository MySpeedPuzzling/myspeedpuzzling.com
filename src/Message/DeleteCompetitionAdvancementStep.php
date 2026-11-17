<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Removes an advancement step (competition toolkit A8) - only the step: rounds, marks and entries stay as they are.
 */
readonly final class DeleteCompetitionAdvancementStep implements SerializedByLock
{
    public function __construct(
        public string $stepId,
        // The step's event, as the caller authorised it - the handler refuses a step of another event
        public string $competitionId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
