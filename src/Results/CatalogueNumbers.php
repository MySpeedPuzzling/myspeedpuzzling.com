<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Size of the public puzzle database, as quoted on the tracker page and the homepage.
 */
readonly final class CatalogueNumbers
{
    public function __construct(
        public int $puzzles,
        public int $brands,
        public int $puzzlesWithEan,
        public int $solveTimes,
    ) {
    }

    /**
     * @param array{
     *     puzzles: int|string,
     *     brands: int|string,
     *     puzzles_with_ean: int|string,
     *     solve_times: int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzles: (int) $row['puzzles'],
            brands: (int) $row['brands'],
            puzzlesWithEan: (int) $row['puzzles_with_ean'],
            solveTimes: (int) $row['solve_times'],
        );
    }
}
