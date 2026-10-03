<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How the puzzle list of a comparison with 3+ subjects is shown (docs/features/player-comparison.md, D1): a table with
 * a column per subject (the default), or a ranked card per puzzle. Persisted per player. Two subjects are always shown
 * as side-by-side rows, whatever is stored here. (A third "Duel" view was removed on 2026-10-03 after user feedback.)
 */
enum ComparisonView: string
{
    case Table = 'table';
    case Cards = 'cards';
}
