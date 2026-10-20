<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\BrandNeedingImprovement;
use SpeedPuzzling\Web\Results\PuzzleNeedingImprovement;

/**
 * Approved, visible puzzles players can help complete - for now the ones without an EAN. The most solved first:
 * those are the puzzles people scan and search most.
 */
readonly final class GetPuzzlesNeedingImprovement
{
    public const int BRAND_FILTER_MIN_PUZZLES = 3;

    private const string MISSING_EAN = <<<SQL
puzzle.approved = true
    AND (puzzle.ean IS NULL OR puzzle.ean = '')
    AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function countMissingEan(null|string $manufacturerId): int
    {
        $missingEan = self::MISSING_EAN;
        $query = <<<SQL
SELECT COUNT(*)
FROM puzzle
WHERE {$missingEan}
    AND (CAST(:manufacturerId AS uuid) IS NULL OR puzzle.manufacturer_id = CAST(:manufacturerId AS uuid))
SQL;

        $count = $this->database->fetchOne($query, [
            'now' => $this->now(),
            'manufacturerId' => $manufacturerId,
        ]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<PuzzleNeedingImprovement>
     */
    public function missingEan(null|string $manufacturerId, int $limit, int $offset): array
    {
        $missingEan = self::MISSING_EAN;
        // The page is cut first and only its 50 rows get the brand and the pending-proposal check. Sorted by the
        // solved count and the id only (paged sorts end on the id) - adding the name made the sort of ~17 000 rows
        // three times slower (45 ms vs 13 ms warm on a production-sized copy, 2026-10-21)
        $query = <<<SQL
SELECT
    page.puzzle_id,
    page.puzzle_name,
    page.puzzle_image,
    page.pieces_count,
    manufacturer.name AS manufacturer_name,
    page.solved_times_count,
    EXISTS (
        SELECT 1 FROM puzzle_change_request
        WHERE puzzle_change_request.puzzle_id = page.puzzle_id
            AND puzzle_change_request.status = 'pending'
            AND puzzle_change_request.proposed_ean IS NOT NULL
            AND puzzle_change_request.proposed_ean <> ''
    ) AS ean_proposed
FROM (
    SELECT
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        puzzle.image AS puzzle_image,
        puzzle.pieces_count,
        puzzle.manufacturer_id,
        COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times_count
    FROM puzzle
    LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
    WHERE {$missingEan}
        AND (CAST(:manufacturerId AS uuid) IS NULL OR puzzle.manufacturer_id = CAST(:manufacturerId AS uuid))
    ORDER BY solved_times_count DESC, puzzle.id
    LIMIT :limit OFFSET :offset
) page
LEFT JOIN manufacturer ON manufacturer.id = page.manufacturer_id
ORDER BY page.solved_times_count DESC, page.puzzle_id
SQL;

        /** @var list<array{puzzle_id: string, puzzle_name: string, puzzle_image: null|string, pieces_count: int|string, manufacturer_name: null|string, solved_times_count: int|string, ean_proposed: bool}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'now' => $this->now(),
            'manufacturerId' => $manufacturerId,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return array_map(static fn (array $row): PuzzleNeedingImprovement => new PuzzleNeedingImprovement(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleImage: $row['puzzle_image'],
            piecesCount: (int) $row['pieces_count'],
            manufacturerName: $row['manufacturer_name'],
            solvedTimesCount: (int) $row['solved_times_count'],
            eanProposed: $row['ean_proposed'],
        ), $rows);
    }

    /**
     * The brands with at least BRAND_FILTER_MIN_PUZZLES puzzles missing an EAN (and the selected one), the most such
     * puzzles first - the filter's choices. Every brand would be 1 600+ options, mostly with one puzzle
     *
     * @return list<BrandNeedingImprovement>
     */
    public function brandsMissingEan(null|string $selectedManufacturerId): array
    {
        $missingEan = self::MISSING_EAN;
        $query = <<<SQL
SELECT manufacturer.id AS manufacturer_id, manufacturer.name AS manufacturer_name, COUNT(*) AS puzzles_count
FROM puzzle
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE {$missingEan}
GROUP BY manufacturer.id, manufacturer.name
HAVING COUNT(*) >= :minPuzzles OR manufacturer.id = CAST(:selectedManufacturerId AS uuid)
ORDER BY puzzles_count DESC, manufacturer.name
SQL;

        /** @var list<array{manufacturer_id: string, manufacturer_name: string, puzzles_count: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'now' => $this->now(),
            'minPuzzles' => self::BRAND_FILTER_MIN_PUZZLES,
            'selectedManufacturerId' => $selectedManufacturerId,
        ]);

        return array_map(static fn (array $row): BrandNeedingImprovement => new BrandNeedingImprovement(
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            puzzlesCount: (int) $row['puzzles_count'],
        ), $rows);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
