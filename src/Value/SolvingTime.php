<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class SolvingTime
{
    public function __construct(
        public null|int $seconds
    ) {
    }

    public static function fromUserInput(null|string $time): self
    {
        if ($time === null) {
            return new self(null);
        }

        /** @var array<int, numeric-string> $parts */
        $parts = explode(':', $time);
        assert(count($parts) === 3 || count($parts) === 2);

        if (count($parts) === 3) {
            $seconds = (int) $parts[2];
            $seconds += 60 * (int) $parts[1];
            $seconds += 60 * 60 * (int) $parts[0];
        } else {
            $seconds = (int) $parts[1];
            $seconds += 60 * (int) $parts[0];
        }

        return new self($seconds);
    }

    public function calculatePpm(int $pieces, int $puzzlersCount = 1): float
    {
        if ($this->seconds === null) {
            return 0;
        }

        return round($pieces / ($this->seconds / 60) / $puzzlersCount, 2);
    }
}
