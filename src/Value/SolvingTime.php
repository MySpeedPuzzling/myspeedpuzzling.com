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
        assert(count($parts) === 3);

        $seconds = 0;
        $seconds += 60 * 60 * (int) $parts[0];
        $seconds += 60 * (int) $parts[1];
        $seconds += (int) $parts[2];

        return new self($seconds);
    }
}
