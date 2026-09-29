<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Value\PiecesRange;

/**
 * The numbered puzzle lists of the catalogue pages (brand hub, pieces hub,
 * brand × pieces): every visible puzzle of a brand and/or piece count, most
 * solved first, then by name and id - a total order, so no puzzle is skipped
 * or repeated between two pages.
 *
 * Deliberately not SearchPuzzle::byUserInput(): these pages have no search,
 * tag or difficulty filter, and its search-ranking CTE needed 51 ms for the
 * first and 93 ms for the last page of the 500-piece hub on the production
 * copy (2026-09-30) - this query 13 and 19 ms. The same row shape
 * (PuzzleOverview) keeps the list items identical.
 */
readonly final class GetCataloguePuzzles
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<PuzzleOverview>
     */
    public function page(null|string $brandId, PiecesRange $pieces, int $offset, int $limit): array
    {
        // Two steps: the page is picked from narrow rows (id, solves, name) and
        // only its puzzles are hydrated - sorting the full wide rows made deep
        // pages twice as slow. Names compare bytewise (COLLATE "C"): just as
        // stable a tiebreaker, and the last page of the 500-piece hub (15k rows
        // sorted) drops from 26 to 16 ms against the locale collation.
        $query = <<<SQL
WITH page AS (
    SELECT
        puzzle.id,
        COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times,
        puzzle.name
    FROM puzzle
    LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
    WHERE (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
        AND (:brandId::uuid IS NULL OR puzzle.manufacturer_id = :brandId)
        AND (:minPieces::int IS NULL OR puzzle.pieces_count >= :minPieces)
        AND (:maxPieces::int IS NULL OR puzzle.pieces_count <= :maxPieces)
    ORDER BY solved_times DESC, puzzle.name COLLATE "C", puzzle.id
    LIMIT :limit OFFSET :offset
)
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.hide_image_until,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.is_available,
    puzzle.approved AS puzzle_approved,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.slug AS manufacturer_slug,
    puzzle.ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    page.solved_times,
    puzzle_statistics.average_time_solo,
    puzzle_statistics.fastest_time_solo,
    puzzle_statistics.average_time_duo,
    puzzle_statistics.fastest_time_duo,
    puzzle_statistics.average_time_team,
    puzzle_statistics.fastest_time_team
FROM page
INNER JOIN puzzle ON puzzle.id = page.id
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
ORDER BY page.solved_times DESC, page.name COLLATE "C", page.id
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'brandId' => $brandId,
                'minPieces' => $pieces->minPieces,
                'maxPieces' => $pieces->maxPieces,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_approved: bool,
             *     manufacturer_id: string,
             *     manufacturer_name: string,
             *     manufacturer_slug: null|string,
             *     pieces_count: int,
             *     average_time_solo: null|string,
             *     fastest_time_solo: null|int,
             *     average_time_duo: null|string,
             *     fastest_time_duo: null|int,
             *     average_time_team: null|string,
             *     fastest_time_team: null|int,
             *     solved_times: int,
             *     is_available: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string,
             *     hide_image_until: null|string,
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }

    public function count(null|string $brandId, PiecesRange $pieces): int
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM puzzle
WHERE (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    AND (:brandId::uuid IS NULL OR puzzle.manufacturer_id = :brandId)
    AND (:minPieces::int IS NULL OR puzzle.pieces_count >= :minPieces)
    AND (:maxPieces::int IS NULL OR puzzle.pieces_count <= :maxPieces)
SQL;

        $count = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'brandId' => $brandId,
                'minPieces' => $pieces->minPieces,
                'maxPieces' => $pieces->maxPieces,
            ])
            ->fetchOne();
        assert(is_int($count));

        return $count;
    }
}
