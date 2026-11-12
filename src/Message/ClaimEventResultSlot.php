<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "This was my team" on an imported event result (docs/features/event-results-import/README.md "Claiming a spot").
 */
readonly final class ClaimEventResultSlot implements SerializedByLock
{
    public function __construct(
        public string $timeId,
        public string $playerId,
        // "This was my first try at this puzzle"
        public bool $firstTry = false,
        // "Is this the same result?" - the player's own result of the puzzle to delete, the imported one stays
        public null|string $sameResultTimeId = null,
    ) {
    }

    public function lockKey(): string
    {
        return self::lockKeyOf($this->timeId);
    }

    /**
     * One key for every change of a result's spots - claims, releases and removals take turns.
     */
    public static function lockKeyOf(string $timeId): string
    {
        return 'event-result-spots-' . strtolower($timeId);
    }
}
