<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

readonly final class CreateCompetitionTeams implements SerializedByLock
{
    /**
     * @param list<string> $names one team per name, an empty list adds one unnamed team
     */
    public function __construct(
        // The event the organiser was authorised for - the round must be one of its own
        public string $competitionId,
        public string $roundId,
        public array $names,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
