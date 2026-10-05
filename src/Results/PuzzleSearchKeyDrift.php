<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A puzzle whose stored search keys differ from the ones its names and codes give today (GetPuzzleSearchKeyDrift) -
 * what myspeedpuzzling:rebuild-puzzle-search-keys would write.
 */
readonly final class PuzzleSearchKeyDrift
{
    public function __construct(
        public string $puzzleId,
        public null|string $storedNames,
        public string $expectedNames,
        public null|string $storedCodes,
        public null|string $expectedCodes,
    ) {
    }
}
