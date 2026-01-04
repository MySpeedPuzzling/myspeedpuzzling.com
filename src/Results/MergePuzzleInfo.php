<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class MergePuzzleInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public null|int $piecesCount,
        public null|string $image,
        public null|string $manufacturerName,
        public int $timesCount,
    ) {
    }
}
