<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class Month
{
    public function __construct(
        public int $month,
        public int $year,
    ) {
    }
}
