<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class StopwatchMilestone
{
    public function __construct(
        public string $label,
        public int $timeSeconds,
        public string $type,
        public null|string $avatar,
        public null|int $rank = null,
    ) {
    }
}
