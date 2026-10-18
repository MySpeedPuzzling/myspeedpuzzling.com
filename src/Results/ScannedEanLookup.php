<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The puzzles a scanned barcode means (FindPuzzlesByScannedEan): $correctedEan is the catalogue code the puzzles carry
 * when the scanned one was a misread of it, null when the code was found as scanned (or nothing was found).
 */
readonly final class ScannedEanLookup
{
    /**
     * @param list<PuzzleOverview> $puzzles
     */
    public function __construct(
        public array $puzzles,
        public null|string $correctedEan = null,
    ) {
    }
}
