<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

readonly final class RestoreCompetitionParticipant implements SerializedByLock
{
    public function __construct(
        // The event the organiser was authorised for - the participant must be one of its own
        public string $competitionId,
        public string $participantId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
