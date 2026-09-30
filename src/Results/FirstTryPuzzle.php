<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class FirstTryPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
    ) {
    }
}
