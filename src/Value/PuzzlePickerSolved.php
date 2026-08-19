<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * My-history filter of the puzzle picker. "Solved" has the same meaning as on
 * the player's "Unsolved puzzles" page: any solving time of mine, including
 * duo/team solves where I am only a participant.
 */
enum PuzzlePickerSolved: string
{
    case Any = 'any';
    case Never = 'never';
    case Before = 'before';
}
