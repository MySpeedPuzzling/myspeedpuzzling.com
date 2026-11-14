<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

enum PiecesCertainty: string
{
    /** Nothing in the cell */
    case NoPieces = 'no_pieces';
    /** A whole number */
    case Certain = 'certain';
    /** A number written with a thousands mark or a word: "1,000", "500 pcs" */
    case ReadFromText = 'read_from_text';
    /** No piece count can be taken - a problem of the row with choices (PiecesProblem) */
    case Problem = 'problem';
}
