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
        public null|string $manufacturerName,
        public bool $hidden,
    ) {
    }
}
