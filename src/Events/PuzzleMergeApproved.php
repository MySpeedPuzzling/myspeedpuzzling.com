<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

readonly final class PuzzleMergeApproved implements RequiresFreshEntityManagerState
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
