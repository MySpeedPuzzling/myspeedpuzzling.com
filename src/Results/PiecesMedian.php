<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PiecesMedian
{
    public function __construct(
        public int $piecesCount,
        public int $solvesCount,
        public int $medianSeconds,
    ) {
    }
}
