<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

enum PiecesProblem: string
{
    /** "13x99", "500*4", "2×500": several puzzles in one box - the numbers are handed out, none is chosen */
    case Multipack = 'multipack';
    /** "?", "mini?", "~1000": the player does not know, or is not sure */
    case Unknown = 'unknown';
    /** "731.78": a number that is no count (an average, a price) */
    case NotWhole = 'not_whole';
    case Zero = 'zero';
    case Negative = 'negative';
    /** More than PiecesReader::MOST_PIECES */
    case TooMany = 'too_many';
    /** Words, or anything else */
    case Unreadable = 'unreadable';
}
