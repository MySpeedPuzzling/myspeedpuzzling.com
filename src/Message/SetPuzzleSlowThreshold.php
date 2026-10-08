<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * "A hard puzzle" in the time verification queue (docs/features/suspicious-time-review.md, "A hard puzzle"): a slow
 * time on the puzzle is raised only from slowThreshold × its expectation, until its piece count changes. Null removes
 * the threshold. The scan judges the puzzle's times again (DetectSuspiciousTimes with onlyPuzzleId, right after).
 */
readonly final class SetPuzzleSlowThreshold implements SerializedByLock
{
    // Below the lowest rule ratio (SuspiciousTimeClassifier::SLOW_PREDICTION_RAISE_RATIO) a threshold changes nothing
    public const float MIN = 3.0;
    public const float MAX = 100.0;

    /**
     * @param int $seenPiecesCount the piece count the moderator saw - refused when the puzzle has another one by now
     */
    public function __construct(
        public string $puzzleId,
        public string $decidedById,
        public int $seenPiecesCount,
        public null|float $slowThreshold,
    ) {
    }

    public static function isValid(float $slowThreshold): bool
    {
        return $slowThreshold >= self::MIN && $slowThreshold <= self::MAX;
    }

    /**
     * The lock of every change of the puzzle's record - the piece count read here cannot change underneath.
     */
    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
