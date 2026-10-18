<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "What from my shelf suits me?" - members only: for everybody else only the number of unsolved shelf puzzles.
 */
readonly final class ShelfPicksResult
{
    /**
     * @param list<ShelfPick> $picks best suited first
     */
    public function __construct(
        public bool $locked,
        public int $unsolvedOnShelf,
        /** Unsolved shelf puzzles with an expected time */
        public int $withExpectedTime,
        public array $picks,
    ) {
    }
}
