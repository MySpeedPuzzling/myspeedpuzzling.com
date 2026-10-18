<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum BoxTextMismatchSort: string
{
    // The puzzles with the most results first - a fix there matters most
    case MostSolved = 'most_solved';
    // The latest analyses first
    case Newest = 'newest';
}
