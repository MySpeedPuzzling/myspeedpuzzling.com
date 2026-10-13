<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * A personal link for every participant of the competition who has none yet - also those added after online timing
 * was turned on (docs/features/online-competitions/README.md §5.4).
 */
readonly final class GenerateOnlineTokens implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
