<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\PartnerKind;

/**
 * "I'd like to puzzle with you" (docs/features/partner-finder.md, D6). Marks the sender going on a plain event, so it
 * takes turns with every other write to the event's participants.
 */
readonly final class SendPartnerRequest implements SerializedByLock
{
    public function __construct(
        public string $requestId,
        public string $listingId,
        public string $competitionId,
        public string $fromPlayerId,
        public PartnerKind $kind,
        public null|string $note,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
