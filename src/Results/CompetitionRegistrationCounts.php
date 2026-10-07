<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class CompetitionRegistrationCounts
{
    public function __construct(
        public int $spotsTaken,
        public int $waitlisted,
    ) {
    }

    public function isFull(null|int $capacity): bool
    {
        return $capacity !== null && $this->spotsTaken >= $capacity;
    }
}
