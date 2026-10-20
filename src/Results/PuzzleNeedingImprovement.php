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
        /** A player's proposal with an EAN waits for a moderator */
        public bool $eanProposed,
    ) {
    }
}
