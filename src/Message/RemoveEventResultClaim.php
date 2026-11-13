<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Not on our team": another claimer, the organizer's team or an admin takes a player's claim of an imported event
 * result back (docs/features/event-results-import/README.md "Gates").
 */
readonly final class RemoveEventResultClaim implements SerializedByLock
{
    public function __construct(
        public string $timeId,
        // Whose spot
        public string $playerId,
        // Who says so
        public string $byPlayerId,
        public null|string $reason = null,
    ) {
    }

    public function lockKey(): string
    {
        return ClaimEventResultSlot::lockKeyOf($this->timeId);
    }
}
