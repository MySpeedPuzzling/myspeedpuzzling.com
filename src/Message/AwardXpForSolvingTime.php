<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AwardXpForSolvingTime
{
    public function __construct(
        public string $solvingTimeId,
    ) {
    }
}
