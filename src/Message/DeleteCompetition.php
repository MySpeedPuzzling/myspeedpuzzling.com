<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Takes turns with every write to the event's participants (CompetitionParticipantsLock) - nothing of the event is
 * written while it goes away, and a "has results" check runs under the lock the results desk records under.
 */
readonly final class DeleteCompetition implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        // The internal API deletes only what nobody has a result in (CompetitionHasResults); the web form asks its own way
        public bool $refuseWhenItHasResults = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
