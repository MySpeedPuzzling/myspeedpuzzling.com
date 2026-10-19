<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Where a puzzle's difficulty stands among the player's own collection puzzles of the same piece count with a measured
 * difficulty (GetPersonalEstimates::collectionRank()) - the puzzle page card's context line, "The 2nd-hardest
 * 1000-piece puzzle in your collection". Said only when it is worth saying: the puzzle is in a collection of the
 * player's, it is among the 3 hardest (or else easiest) and the collection holds at least 5 such puzzles.
 */
readonly final class CollectionDifficultyRank
{
    public const int MAX_RANK = 3;
    public const int MIN_PUZZLES = 5;

    public function __construct(
        public bool $inCollection,
        // How many of the other puzzles are harder / easier
        public int $harder,
        public int $easier,
        public int $others,
    ) {
    }

    /**
     * @return null|array{hardest: bool, rank: int<1, 3>}
     */
    public function context(): null|array
    {
        if ($this->inCollection === false || $this->others + 1 < self::MIN_PUZZLES) {
            return null;
        }

        if ($this->harder < self::MAX_RANK && $this->harder <= $this->easier) {
            return ['hardest' => true, 'rank' => self::rank($this->harder)];
        }

        if ($this->easier < self::MAX_RANK) {
            return ['hardest' => false, 'rank' => self::rank($this->easier)];
        }

        return null;
    }

    /**
     * @return int<1, 3>
     */
    private static function rank(int $before): int
    {
        return match ($before) {
            0 => 1,
            1 => 2,
            default => 3,
        };
    }
}
