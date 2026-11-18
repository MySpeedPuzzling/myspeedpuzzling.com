<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The person's one-click "Don't send me more invitations for this event" (docs/features/competitions-management/
 * participant-linking.md §9.1, R8) - through the signed link of the invitation, no sign-in. Idempotent.
 */
readonly final class RefuseParticipantClaimInvitations implements SerializedByLock
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
