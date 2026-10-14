<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "Reset" - the round's stopwatch back to not started. For a round with online timing it starts a fresh run and checks
 * the round's official results first (ResetRoundStopwatchHandler) - under the event's participants lock, like every
 * write of an official result, so a result recorded at that very moment never slips past the check.
 */
readonly final class ResetRoundStopwatch implements SerializedByLock
{
    public function __construct(
        public string $roundId,
        // The competition the caller authorised - a round of another one is refused
        public string $competitionId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
