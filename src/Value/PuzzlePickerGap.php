<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * "Compared to my prediction" filter of the puzzle picker (members with
 * predictions): keep only puzzles where my fastest solo time is slower than my
 * predicted time (room to improve — a PB chance) or faster than it (I outperform
 * on this one). Needs at least one solo solve and a prediction on the puzzle.
 */
enum PuzzlePickerGap: string
{
    case Slower = 'slower';
    case Faster = 'faster';
}
