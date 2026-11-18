<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * A personal claim link for every participant of the event on the list, not linked and without one yet - or only for
 * the listed ones (docs/features/competitions-management/participant-linking.md §5.2): dispatched when the organiser
 * copies a link, downloads the claim links CSV or opens the claim slips. The handler returns how many got one.
 */
readonly final class GenerateParticipantClaimTokens implements SerializedByLock
{
    /**
     * @param null|list<string> $participantIds null = everybody without one
     */
    public function __construct(
        public string $competitionId,
        public null|array $participantIds = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
