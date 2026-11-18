<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "New link" (docs/features/competitions-management/participant-linking.md §5.2, D5): the participant's personal claim
 * link is replaced - the old one stops working at once. The handler returns the new token.
 */
readonly final class RegenerateParticipantClaimToken implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $participantId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
