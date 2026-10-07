<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class EditionRoundPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public null|string $manufacturerName,
        public bool $hidden,
        // Shown, but its picture is still under wraps (the round's "image only" hiding or the puzzle's own
        // hide_image_until) - not revealed yet
        public bool $imageHidden = false,
    ) {
    }
}
