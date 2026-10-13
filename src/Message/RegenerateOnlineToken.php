<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * A new personal link for one participant - the old one stops working at once (docs/features/online-competitions/
 * README.md §5.4).
 */
readonly final class RegenerateOnlineToken implements SerializedByLock
{
    public function __construct(
        // The competition the caller authorised - the participant must be one of its own
        public string $competitionId,
        public string $participantId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
