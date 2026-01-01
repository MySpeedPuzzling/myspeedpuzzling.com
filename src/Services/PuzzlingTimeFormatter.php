<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class PuzzlingTimeFormatter
{
    public const TIME_FORMAT = '/^(([0-9]{1,2})+:)?([0-5]?[0-9]):([0-5]?[0-9])$/';

    public function formatTime(int $interval): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->hoursElapsed($interval),
            $this->minutesElapsed($interval),
            $this->secondsElapsed($interval)
        );
    }

    public function daysElapsed(null|int $interval): int
    {
        $interval = (int) abs($interval ?? 0);

        return (int) floor($interval / (3600 * 24));
    }

    public function hoursElapsed(null|int $interval, bool $overlap = true): string
    {
        $interval = (int) abs($interval ?? 0);
        $hours = floor($interval / 3600);

        if ($overlap === false) {
            $hours %= 24;
        }

        return str_pad((string) $hours, 2, '0', STR_PAD_LEFT);
    }

    public function minutesElapsed(null|int $interval): string
    {
        $interval = (int) abs($interval ?? 0);
        return str_pad((string) floor(((int) ($interval / 60)) % 60), 2, '0', STR_PAD_LEFT);
    }

    public function secondsElapsed(null|int $interval): string
    {
        $interval = (int) abs($interval ?? 0);
        return str_pad((string) ($interval % 60), 2, '0', STR_PAD_LEFT);
    }
}
