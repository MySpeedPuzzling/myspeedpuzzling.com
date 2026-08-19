<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where the puzzle picker draws candidates from.
 */
enum PuzzlePickerSource: string
{
    /** Puzzles on my shelf: system + custom collection items, plus borrowed ones. */
    case Mine = 'mine';

    /** Discovery: puzzles that are not in any of my collections. */
    case NotMine = 'not_mine';

    /** Every approved puzzle in the database. */
    case Any = 'any';
}
