<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Who to ask for which puzzle (BorrowPlanBuilder): owners in the order they were picked, each puzzle with one owner only.
 */
final readonly class BorrowPlan
{
    /**
     * @param array<string, list<string>> $puzzlesByOwner owner id => puzzle ids, in pick order
     * @param list<string> $unassigned puzzles nobody can lend
     */
    public function __construct(
        public array $puzzlesByOwner,
        public array $unassigned,
    ) {
    }

    /**
     * @return list<string>
     */
    public function assignedPuzzleIds(): array
    {
        return array_merge(...array_values($this->puzzlesByOwner));
    }

    public function ownerOf(string $puzzleId): null|string
    {
        foreach ($this->puzzlesByOwner as $ownerId => $puzzleIds) {
            if (in_array($puzzleId, $puzzleIds, true)) {
                return $ownerId;
            }
        }

        return null;
    }
}
