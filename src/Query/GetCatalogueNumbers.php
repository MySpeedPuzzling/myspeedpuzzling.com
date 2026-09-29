<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\CatalogueNumbers;

/**
 * The numbers behind "the jigsaw puzzle database": only what a visitor can actually find in it.
 * 30-50 ms on the production copy (40k puzzles, 520k times) - read it through
 * CatalogueNumbersProvider, which caches it.
 */
readonly final class GetCatalogueNumbers
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function current(): CatalogueNumbers
    {
        // Puzzles: approved and not hidden (hide_until keeps placeholder puzzles secret).
        // Brands: approved brands with at least one such puzzle - a brand still waiting for
        // approval is often a duplicate about to be merged. Solve times: timed and not flagged
        // as suspicious, like every public statistic.
        $query = <<<SQL
WITH visible_puzzle AS (
    SELECT puzzle.manufacturer_id, puzzle.ean
    FROM puzzle
    WHERE puzzle.approved = true
        AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
)
SELECT
    (SELECT COUNT(*) FROM visible_puzzle) AS puzzles,
    (
        SELECT COUNT(DISTINCT visible_puzzle.manufacturer_id)
        FROM visible_puzzle
        INNER JOIN manufacturer ON manufacturer.id = visible_puzzle.manufacturer_id
        WHERE manufacturer.approved = true
    ) AS brands,
    (
        SELECT COUNT(*)
        FROM visible_puzzle
        WHERE visible_puzzle.ean IS NOT NULL AND visible_puzzle.ean <> ''
    ) AS puzzles_with_ean,
    (
        SELECT COUNT(*)
        FROM puzzle_solving_time
        WHERE puzzle_solving_time.seconds_to_solve IS NOT NULL
            AND puzzle_solving_time.suspicious = false
    ) AS solve_times
SQL;

        /**
         * @var array{
         *     puzzles: int|string,
         *     brands: int|string,
         *     puzzles_with_ean: int|string,
         *     solve_times: int|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAssociative();

        return CatalogueNumbers::fromDatabaseRow($row);
    }
}
