<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Direction of the "my time is under / over N minutes" filter of the puzzle picker.
 */
enum PuzzlePickerMyTimeOperator: string
{
    case Under = 'lt';
    case Over = 'gt';

    public function sql(): string
    {
        return match ($this) {
            self::Under => '<',
            self::Over => '>',
        };
    }
}
