<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * The lend/borrow section of the puzzle library summary: how many puzzles
 * the player has lent out and how many they are currently borrowing, plus
 * the owner's visibility setting ("public" or "private"). Both counts are 0
 * for a section the token may not see.
 */
final class LibraryLendBorrowSectionResponse
{
    public function __construct(
        public int $lentCount,
        public int $borrowedCount,
        public string $visibility,
    ) {
    }
}
