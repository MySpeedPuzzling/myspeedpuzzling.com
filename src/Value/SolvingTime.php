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

    public static function fromHoursMinutesSeconds(
        null|int $hours,
        null|int $minutes,
        null|int $seconds,
    ): self {
        if ($hours === null && $minutes === null && $seconds === null) {
            return new self(null);
        }

        $totalSeconds = ($seconds ?? 0)
            + (($minutes ?? 0) * 60)
            + (($hours ?? 0) * 3600);

        if ($totalSeconds === 0) {
            return new self(null);
        }

        return new self($totalSeconds);
    }

    public function toTimeString(): null|string
    {
        if ($this->seconds === null) {
            return null;
        }

        $hours = intdiv($this->seconds, 3600);
        $minutes = intdiv($this->seconds % 3600, 60);
        $seconds = $this->seconds % 60;

        return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
    }

    public function calculatePpm(int $pieces, int $puzzlersCount = 1): float
    {
        $puzzlersCount = max(1, $puzzlersCount);

        if ($this->seconds === null || $this->seconds === 0 || $pieces === 0) {
            return 0;
        }

        return round($pieces / ($this->seconds / 60) / $puzzlersCount, 2);
    }
}
