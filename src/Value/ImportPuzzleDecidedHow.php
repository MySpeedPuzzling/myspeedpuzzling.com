<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Who made the decision of a distinct puzzle of an import. Only the player's own word is never touched by a later
 * run of the pairing; the other two are worked out again from what holds then.
 */
enum ImportPuzzleDecidedHow: string
{
    /** The player, in this import - also when they took a decision back */
    case Player = 'player';
    /** The player, earlier: their remembered decision for the same puzzle key (import_puzzle_decision) */
    case Remembered = 'remembered';
    /** The rules: only a puzzle of the "found" tier */
    case Automatic = 'automatic';
}
