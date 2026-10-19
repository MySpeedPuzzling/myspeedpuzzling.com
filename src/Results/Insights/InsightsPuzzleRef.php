<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * The few facts an Insights list row needs about a puzzle. Never built for a secret puzzle (hide_until /
 * hide_image_until in the future): fromRow() answers null, and the builders leave such a puzzle out of every
 * suggestion (or show the number without the puzzle) - docs/features/insights/computation.md §7.
 */
readonly final class InsightsPuzzleRef
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public null|string $image,
        public null|string $brandName,
        public int $piecesCount,
    ) {
    }

    /**
     * @param array{puzzle_id: string, puzzle_name: string, puzzle_image: null|string, brand_name: null|string, pieces_count: int|string, puzzle_secret: bool|int|string, ...} $row
     */
    public static function fromRow(array $row): null|self
    {
        if (self::isSecret($row['puzzle_secret'])) {
            return null;
        }

        return new self(
            puzzleId: $row['puzzle_id'],
            name: $row['puzzle_name'],
            image: $row['puzzle_image'],
            brandName: $row['brand_name'],
            piecesCount: (int) $row['pieces_count'],
        );
    }

    public static function isSecret(bool|int|string $flag): bool
    {
        return $flag === true || $flag === 1 || $flag === '1' || $flag === 't';
    }

    /**
     * The columns fromRow() reads, for a query joining `puzzle {$puzzle}` and `LEFT JOIN manufacturer {$brand}`; the
     * query binds :now.
     */
    public static function sqlColumns(string $puzzle = 'p', string $brand = 'm'): string
    {
        return "{$puzzle}.id AS puzzle_id,
            {$puzzle}.name AS puzzle_name,
            {$puzzle}.image AS puzzle_image,
            {$brand}.name AS brand_name,
            {$puzzle}.pieces_count,
            " . self::sqlSecret($puzzle) . ' AS puzzle_secret';
    }

    /**
     * A puzzle kept secret now - the puzzle or only its picture hidden until a moment in the future.
     */
    public static function sqlSecret(string $puzzle = 'p'): string
    {
        return "(({$puzzle}.hide_until IS NOT NULL AND {$puzzle}.hide_until > CAST(:now AS timestamp)) OR ({$puzzle}.hide_image_until IS NOT NULL AND {$puzzle}.hide_image_until > CAST(:now AS timestamp)))";
    }
}
