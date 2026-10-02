<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * The tracker moved a result to another puzzle in the edit form (docs/features/duplicate-results.md, Layer 4).
 * For the puzzle it left this is like a deletion - its statistics and insights lose the result - so the fields
 * mirror PuzzleSolvingTimeDeleted: `puzzleId` is the puzzle the result LEFT. The puzzle it moved to learns about it
 * from the PuzzleSolvingTimeModified the same edit records.
 */
readonly final class PuzzleSolvingTimeMovedToOtherPuzzle
{
    public function __construct(
        public UuidInterface $puzzleSolvingTimeId,
        public UuidInterface $puzzleId,
        public UuidInterface $playerId,
        public int $piecesCount,
    ) {
    }
}
