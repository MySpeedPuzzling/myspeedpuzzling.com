<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * "Piece count is right" on a puzzle card of the time verification queue (docs/features/suspicious-time-review.md,
 * "The piece count is wrong"): the puzzle's cases go on one by one, until its piece count changes.
 */
readonly final class ConfirmPuzzlePiecesCount implements SerializedByLock
{
    /**
     * @param int $seenPiecesCount the piece count the card showed - refused when the puzzle has another one by now
     */
    public function __construct(
        public string $puzzleId,
        public string $decidedById,
        public int $seenPiecesCount,
    ) {
    }

    /**
     * The lock of every change of the puzzle's record - the piece count read here cannot change underneath.
     */
    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
