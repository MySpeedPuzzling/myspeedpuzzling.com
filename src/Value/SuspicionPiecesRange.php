<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The piece-count ranges the community pace is measured in (suspicious_time_reference): wide enough that every range
 * has a sample for solo, pairs and teams alike, close enough that a median means something for its puzzles. Bounds
 * inclusive. PHP (of()) and SQL (sql()) must agree - SuspicionPiecesRangeParityTest.
 */
enum SuspicionPiecesRange: string
{
    case UpTo199 = '1-199';
    case From200 = '200-499';
    case From500 = '500-750';
    case From751 = '751-998';
    case From999 = '999-1200';
    case From1201 = '1201-2000';
    case From2001 = '2001-5000';
    case From5001 = '5001+';

    public static function of(int $piecesCount): self
    {
        return match (true) {
            $piecesCount <= 199 => self::UpTo199,
            $piecesCount <= 499 => self::From200,
            $piecesCount <= 750 => self::From500,
            $piecesCount <= 998 => self::From751,
            $piecesCount <= 1200 => self::From999,
            $piecesCount <= 2000 => self::From1201,
            $piecesCount <= 5000 => self::From2001,
            default => self::From5001,
        };
    }

    /**
     * The same as of() as an SQL expression over a piece count.
     */
    public static function sql(string $piecesCountExpression): string
    {
        return "CASE"
            . " WHEN {$piecesCountExpression} <= 199 THEN '" . self::UpTo199->value . "'"
            . " WHEN {$piecesCountExpression} <= 499 THEN '" . self::From200->value . "'"
            . " WHEN {$piecesCountExpression} <= 750 THEN '" . self::From500->value . "'"
            . " WHEN {$piecesCountExpression} <= 998 THEN '" . self::From751->value . "'"
            . " WHEN {$piecesCountExpression} <= 1200 THEN '" . self::From999->value . "'"
            . " WHEN {$piecesCountExpression} <= 2000 THEN '" . self::From1201->value . "'"
            . " WHEN {$piecesCountExpression} <= 5000 THEN '" . self::From2001->value . "'"
            . " ELSE '" . self::From5001->value . "' END";
    }
}
