<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class PruneSolvingTimeGraveyard
{
    public function __construct(
        public int $retentionMonths,
    ) {
    }
}
