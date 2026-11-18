<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The organiser sends the claim invitation e-mail - the person's personal claim link - to these people of the event
 * (docs/features/competitions-management/participant-linking.md §5.5, R8): the sheet's row and bulk action, the hub's
 * "Send invitations to everybody". The handler keeps who may be invited, makes the missing claim links, records the
 * invitations and queues one SendParticipantClaimInvitation per person; it returns ClaimInvitationsSent.
 */
readonly final class SendParticipantClaimInvitations implements SerializedByLock
{
    /**
     * @param null|list<string> $participantIds null = everybody on the event's list
     */
    public function __construct(
        public string $competitionId,
        public null|array $participantIds,
        public string $byPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
