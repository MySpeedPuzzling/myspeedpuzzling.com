<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The claimant takes their pending request back - "Take back" on the event page (docs/features/competitions-management/
 * participant-linking.md §5.1, §6.1). Nothing pending = nothing changes.
 */
readonly final class WithdrawParticipantLinkRequest implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
