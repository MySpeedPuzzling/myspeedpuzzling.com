<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How the puzzle list of a comparison with 3+ subjects is shown (docs/features/player-comparison.md): a ranked card per
 * puzzle, a table with a column per subject, or duel rows of the two highlighted subjects. Persisted per player.
 */
enum ComparisonView: string
{
    case Cards = 'cards';
    case Table = 'table';
    case Duel = 'duel';
}
