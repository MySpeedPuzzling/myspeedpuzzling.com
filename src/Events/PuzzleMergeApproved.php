<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class PuzzleMergeApproved
{
    /**
     * @param array<string> $puzzleIdsToDelete
     */
    public function __construct(
        public UuidInterface $mergeRequestId,
        public UuidInterface $survivorPuzzleId,
        public array $puzzleIdsToDelete,
    ) {
    }
}
