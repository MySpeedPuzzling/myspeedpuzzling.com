<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One puzzle of a round that every viewer may see right now (RoundPuzzlesVisibleNow) - by name at least: a puzzle whose
 * picture is still under wraps comes without its image.
 */
readonly final class VisibleRoundPuzzle
{
    public function __construct(
        public string $roundPuzzleId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        // Null while the picture is under wraps (the round's "image only" hiding or the puzzle's own hide_image_until)
        public null|string $puzzleImage,
        // Null while the puzzle's own hide_image_until holds it back (the round's "image only" hiding keeps it)
        public null|float $puzzleImageRatio,
        public null|string $manufacturerName,
        public bool $imageHidden,
    ) {
    }
}
