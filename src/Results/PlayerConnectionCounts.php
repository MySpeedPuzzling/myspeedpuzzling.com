<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PlayerConnectionCounts
{
    public function __construct(
        public int $favorites,
        public int $followers,
    ) {
    }
}
