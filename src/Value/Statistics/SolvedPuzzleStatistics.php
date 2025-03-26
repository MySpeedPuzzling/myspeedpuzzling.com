<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Statistics;

readonly final class SolvedPuzzleStatistics
{
    public function __construct(
        public int $count,
        /** @var array<string, int> */
        public array $countPerManufacturer,
    ) {
    }
}
