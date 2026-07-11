<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PiecesHubBrand;
use SpeedPuzzling\Web\Results\PiecesHubStats;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class GetPiecesHub
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function stats(int $piecesCount): PiecesHubStats
    {
        $puzzlesCountQuery = <<<SQL
SELECT COUNT(*)
FROM puzzle
WHERE puzzle.pieces_count = :piecesCount
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
SQL;

        $puzzlesCount = $this->database
            ->executeQuery($puzzlesCountQuery, [
                'piecesCount' => $piecesCount,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchOne();
        assert(is_int($puzzlesCount));

        // Solves count includes every recorded time (solo/duo/team); the
        // median is computed over solo solves only so group times do not
        // skew it.
        $solvesQuery = <<<SQL
SELECT
    COUNT(*) AS solves_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve)
        FILTER (WHERE pst.puzzlers_count = 1) AS median_seconds
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
WHERE puzzle.pieces_count = :piecesCount
    AND pst.seconds_to_solve IS NOT NULL
SQL;

        /** @var array{solves_count: int, median_seconds: null|float|string} $solvesRow */
        $solvesRow = (array) $this->database
            ->executeQuery($solvesQuery, [
                'piecesCount' => $piecesCount,
            ])
            ->fetchAssociative();

        $topBrandsQuery = <<<SQL
SELECT
    manufacturer.name AS brand_name,
    manufacturer.slug AS brand_slug,
    COUNT(*) AS solves_count
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle.pieces_count = :piecesCount
    AND pst.seconds_to_solve IS NOT NULL
    AND manufacturer.approved = true
    AND manufacturer.slug IS NOT NULL
GROUP BY manufacturer.id, manufacturer.name, manufacturer.slug
ORDER BY COUNT(*) DESC
LIMIT 8
SQL;

        /** @var list<array{brand_name: string, brand_slug: string, solves_count: int}> $brandRows */
        $brandRows = $this->database
            ->executeQuery($topBrandsQuery, [
                'piecesCount' => $piecesCount,
            ])
            ->fetchAllAssociative();

        $topBrands = array_map(static function (array $row): PiecesHubBrand {
            return new PiecesHubBrand(
                brandName: $row['brand_name'],
                slug: $row['brand_slug'],
                solvesCount: $row['solves_count'],
            );
        }, $brandRows);

        return new PiecesHubStats(
            piecesCount: $piecesCount,
            puzzlesCount: $puzzlesCount,
            solvesCount: $solvesRow['solves_count'],
            medianSeconds: $solvesRow['median_seconds'] !== null ? (int) round((float) $solvesRow['median_seconds']) : null,
            topBrands: $topBrands,
        );
    }

    /**
     * Most-solved puzzles with an exact piece count. Mirrors the row shape of
     * SearchPuzzle::byUserInput (which cannot express exact piece counts
     * outside its fixed filter buckets).
     *
     * @return list<PuzzleOverview>
     */
    public function mostSolvedPuzzles(int $piecesCount, int $limit): array
    {
        $query = <<<SQL
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
    puzzle.ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times,
    puzzle_statistics.average_time_solo,
    puzzle_statistics.fastest_time_solo,
    puzzle_statistics.average_time_duo,
    puzzle_statistics.fastest_time_duo,
    puzzle_statistics.average_time_team,
    puzzle_statistics.fastest_time_team
FROM puzzle
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE puzzle.pieces_count = :piecesCount
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
ORDER BY solved_times DESC, puzzle.name ASC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCount' => $piecesCount,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'limit' => $limit,
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
}
