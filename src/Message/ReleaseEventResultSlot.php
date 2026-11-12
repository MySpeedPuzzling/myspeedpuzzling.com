<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Remove from my profile" on an imported event result: the player's spot becomes anonymous again
 * (docs/features/event-results-import/README.md "Gates").
 */
readonly final class ReleaseEventResultSlot implements SerializedByLock
{
    public function __construct(
        public string $timeId,
        public string $playerId,
    ) {
    }

    public function lockKey(): string
    {
        return ClaimEventResultSlot::lockKeyOf($this->timeId);
    }
}
