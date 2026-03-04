<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RoundPuzzleForManagement
{
    public function __construct(
        public string $roundPuzzleId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|string $manufacturerName,
        public bool $hideUntilRoundStarts,
    ) {
    }
}
