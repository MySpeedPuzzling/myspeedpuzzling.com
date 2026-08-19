<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Unit of the "last solved more than N ... ago" filter of the puzzle picker.
 */
enum PuzzlePickerSinceUnit: string
{
    case Day = 'd';
    case Week = 'w';
    case Month = 'm';

    /**
     * DateTime modifier taking `now` back by N units, e.g. "-6 months".
     */
    public function modifier(int $amount): string
    {
        return match ($this) {
            self::Day => "-{$amount} days",
            self::Week => "-{$amount} weeks",
            self::Month => "-{$amount} months",
        };
    }
}
