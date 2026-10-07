<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

readonly final class AddCompetitionParticipant implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $country,
        public null|string $externalId,
        public null|string $playerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
