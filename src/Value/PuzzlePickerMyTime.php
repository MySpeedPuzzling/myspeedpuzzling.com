<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which of my solo times the "my time is under / over N minutes" filter of the
 * puzzle picker compares: my fastest, my latest or my first. Puzzles without a
 * solo time never match.
 */
enum PuzzlePickerMyTime: string
{
    case Fastest = 'fastest';
    case Latest = 'latest';
    case First = 'first';

    /**
     * Column of the picker's my_solves CTE holding this time.
     */
    public function column(): string
    {
        return match ($this) {
            self::Fastest => 'fastest_seconds',
            self::Latest => 'latest_seconds',
            self::First => 'first_seconds',
        };
    }
}
