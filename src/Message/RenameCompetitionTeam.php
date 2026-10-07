<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

readonly final class RenameCompetitionTeam implements SerializedByLock
{
    public function __construct(
        // The event the organiser was authorised for - the team must be one of its own
        public string $competitionId,
        public string $teamId,
        /** Free text - whitespace is tidied up, empty leaves the team without a name */
        public null|string $name,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
