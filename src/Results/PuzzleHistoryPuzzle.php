<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Another puzzle a history line names - merged into this one, this one merged into it, reported as its duplicate.
 */
readonly final class PuzzleHistoryPuzzle
{
    public function __construct(
        public string $puzzleId,
        public null|string $name,
        // "Brand · 500 pieces" when the line recorded it
        public null|string $description = null,
    ) {
    }
}
