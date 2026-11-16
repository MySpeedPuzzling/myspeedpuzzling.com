<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * An approved merge was undone (RevertPuzzleMergeRequestHandler): the merged puzzles exist again and their records
 * moved back from the survivor.
 */
readonly final class PuzzleMergeReverted
{
    /**
     * @param list<string> $restoredPuzzleIds
     */
    public function __construct(
        public UuidInterface $mergeRequestId,
        public UuidInterface $survivorPuzzleId,
        public array $restoredPuzzleIds,
    ) {
    }
}
