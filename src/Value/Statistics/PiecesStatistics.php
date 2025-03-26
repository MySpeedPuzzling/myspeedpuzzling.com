<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Statistics;

readonly final class PiecesStatistics
{
    public function __construct(
        public int $pieces,
        public int $count,
        public int $fastestTime,
        public int $averageTime,
    ) {
    }
}
