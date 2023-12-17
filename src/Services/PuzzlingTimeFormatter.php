<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class PuzzlingTimeFormatter
{
    public const TIME_FORMAT = '/^([0-9]{1,2,3}):([0-5][0-9]):([0-5][0-9])$/';

    public function formatTime(int $interval): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->hoursElapsed($interval),
            $this->minutesElapsed($interval),
            $this->secondsElapsed($interval)
        );
    }

    public function hoursElapsed(int $interval): string
    {
        return str_pad((string) floor($interval / 3600), 2, '0', STR_PAD_LEFT);
    }

    public function minutesElapsed(int $interval): string
    {
        return str_pad((string) floor(((int) ($interval / 60)) % 60), 2, '0', STR_PAD_LEFT);
    }

    public function secondsElapsed(int $interval): string
    {
        return str_pad((string) ($interval % 60), 2, '0', STR_PAD_LEFT);
    }
}
