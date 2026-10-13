<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One of the 3 latest puzzles on the player card (docs/features/navigation CARD-03) - a box linking the puzzle page.
 * The picture is null while the puzzle's image is still secret (hide_image_until); a secret puzzle never gets here.
 */
readonly final class PlayerCardRecentPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public null|float $imageRatio,
    ) {
    }
}
