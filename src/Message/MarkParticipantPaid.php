<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The organiser confirms a participant's payment (managed registration). Paid twice = no change, no second e-mail.
 */
readonly final class MarkParticipantPaid implements SerializedByLock
{
    public function __construct(
        // The event the organiser was authorised for - the participant must be one of its own
        public string $competitionId,
        public string $participantId,
        // A waitlisted participant is marked paid only together with an explicit promotion - it takes a spot
        public bool $promoteFromWaitlist = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
