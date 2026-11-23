<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleNeedingImprovement
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleImage,
        public int $piecesCount,
        public null|string $manufacturerName,
        public int $solvedTimesCount,
        public bool $eanMissing,
        /** A player's proposal with an EAN waits for a moderator */
        public bool $eanProposed,
        /** The current picture's crop_trust analysis marks its resolution (low resolution artwork, small original) */
        public bool $imageNeedsImprovement,
        /** The current picture is not the puzzle's picture - only ever true for moderating viewers */
        public bool $wrongPicture,
        /** A player's proposal with a new photo waits for a moderator */
        public bool $imageProposed,
    ) {
    }
}
