<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class Comparison
{
    public int $diff;

    public function __construct(
        public int $playerTime,
        public int $opponentTime,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $puzzleImage,
    ) {
        $this->diff = $this->playerTime - $this->opponentTime;
    }
}
