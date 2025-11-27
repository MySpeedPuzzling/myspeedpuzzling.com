<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PuzzleCondition: string
{
    case LikeNew = 'like_new';
    case Normal = 'normal';
    case NotSoGood = 'not_so_good';
    case MissingPieces = 'missing_pieces';
}
