<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Takes turns with the results desk: the "no official result" check runs under the same lock the results are recorded
 * under, so a pair/team never goes away together with a result saved at the same moment.
 */
readonly final class DeleteCompetitionTeam implements SerializedByLock
{
    public function __construct(
        // The event the organiser was authorised for - the team must be one of its own
        public string $competitionId,
        public string $teamId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
