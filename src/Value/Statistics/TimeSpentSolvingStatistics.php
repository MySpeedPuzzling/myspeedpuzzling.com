<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Statistics;

readonly final class TimeSpentSolvingStatistics
{
    public int $total;

    public function __construct(
        /** @var array<string, int> */
        public array $perDay,
    ) {
        $this->total = (int) array_sum($this->perDay);
    }
}
