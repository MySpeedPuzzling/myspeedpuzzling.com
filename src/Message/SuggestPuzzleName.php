<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * "Suggest another name" (docs/features/puzzle-names/README.md): a player's name becomes a change request of the names
 * only, a moderator's or an admin's is applied at once and recorded in the puzzle's history.
 */
readonly final class SuggestPuzzleName implements SerializedByLock
{
    public function __construct(
        // The change request's id, when the name becomes one
        public string $suggestionId,
        public string $puzzleId,
        public string $playerId,
        public string $name,
        // A BCP 47 tag, null = not known
        public null|string $language,
        // Moderators and admins only: the name becomes the main title, the main title one of the other names. A
        // player's suggestion only ever adds a name.
        public bool $makeMainTitle = false,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
