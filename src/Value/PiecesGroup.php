<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The piece-count groups of the prediction model (docs/features/insights/prediction-research.md, "Piece-count
 * groups"): the size term of the window, the 500-scaled pace for thin sizes and the calibration buckets are kept
 * per group. 500 is a group of its own - 77.7 % of solo solves.
 */
enum PiecesGroup: string
{
    case UpTo300 = 'up_to_300';
    case FiveHundred = 'pieces_500';
    case Thousand = 'pieces_1000';
    case From1500 = 'from_1500';
    case Odd = 'odd';

    public static function fromPiecesCount(int $piecesCount): self
    {
        return match (true) {
            $piecesCount <= 300 => self::UpTo300,
            $piecesCount === 500 => self::FiveHundred,
            $piecesCount === 1000 => self::Thousand,
            $piecesCount >= 1500 => self::From1500,
            default => self::Odd,
        };
    }
}
