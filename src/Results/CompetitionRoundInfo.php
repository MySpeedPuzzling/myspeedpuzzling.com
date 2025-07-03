<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class CompetitionRoundInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public string $color,
        public string $textColor,
    ) {
    }
}
