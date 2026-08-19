<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * "Community results" filter of the puzzle picker, on the number of solo
 * solves the puzzle has (puzzle_statistics.solved_times_solo_count): few
 * results = be a pioneer, rated = enough solvers for the MSP rating (the 20
 * public solvers MspRatingCalculator asks for), popular = a well-known puzzle.
 */
enum PuzzlePickerCommunity: string
{
    case Few = 'few';
    case Rated = 'rated';
    case Popular = 'popular';

    public const int FEW_MAX_SOLVES = 5;

    public const int RATED_MIN_SOLVES = 20;

    public const int POPULAR_MIN_SOLVES = 50;

    /**
     * SQL predicate on the given solo-solves column; a puzzle without a
     * statistics row has zero solves and therefore counts as "few".
     */
    public function sqlCondition(string $column): string
    {
        return match ($this) {
            self::Few => sprintf('COALESCE(%s, 0) <= %d', $column, self::FEW_MAX_SOLVES),
            self::Rated => sprintf('%s >= %d', $column, self::RATED_MIN_SOLVES),
            self::Popular => sprintf('%s >= %d', $column, self::POPULAR_MIN_SOLVES),
        };
    }

    /**
     * The number the chip / option label quotes.
     */
    public function threshold(): int
    {
        return match ($this) {
            self::Few => self::FEW_MAX_SOLVES,
            self::Rated => self::RATED_MIN_SOLVES,
            self::Popular => self::POPULAR_MIN_SOLVES,
        };
    }
}
