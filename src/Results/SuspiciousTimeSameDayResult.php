<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Another result the player saved for the same day as a queued time.
 */
readonly final class SuspiciousTimeSameDayResult
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|int $seconds,
        public PuzzlingType $puzzlingType,
        public bool $flagged,
    ) {
    }
}
