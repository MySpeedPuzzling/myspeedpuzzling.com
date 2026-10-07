<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PiecesHubBrand;
use SpeedPuzzling\Web\Results\PiecesHubStats;

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
        // skew it. A suspicious time is neither counted nor timed.
        $solvesQuery = <<<SQL
SELECT
    COUNT(*) AS solves_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve)
        FILTER (WHERE pst.puzzlers_count = 1) AS median_seconds
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
WHERE puzzle.pieces_count = :piecesCount
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.suspicious = false
SQL;

        /** @var array{solves_count: int, median_seconds: null|float|string} $solvesRow */
        $solvesRow = (array) $this->database
            ->executeQuery($solvesQuery, [
                'piecesCount' => $piecesCount,
            ])
            ->fetchAssociative();

        // The visible-puzzle counts decide whether a brand's badge links to its
        // brand × pieces page (indexable) or to the brand hub.
        $topBrandsQuery = <<<SQL
WITH top_brands AS (
    SELECT
        manufacturer.id AS brand_id,
        manufacturer.name AS brand_name,
        manufacturer.slug AS brand_slug,
        COUNT(*) AS solves_count
    FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    WHERE puzzle.pieces_count = :piecesCount
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND manufacturer.approved = true
        AND manufacturer.slug IS NOT NULL
    GROUP BY manufacturer.id, manufacturer.name, manufacturer.slug
    ORDER BY COUNT(*) DESC
    LIMIT 8
)
SELECT
    top_brands.brand_name,
    top_brands.brand_slug,
    top_brands.solves_count,
    (
        SELECT COUNT(*)
        FROM puzzle
        WHERE puzzle.manufacturer_id = top_brands.brand_id
            AND puzzle.pieces_count = :piecesCount
            AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    ) AS puzzles_count,
    (
        SELECT COUNT(*)
        FROM puzzle
        WHERE puzzle.manufacturer_id = top_brands.brand_id
            AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    ) AS brand_puzzles_count
FROM top_brands
ORDER BY top_brands.solves_count DESC, top_brands.brand_name
SQL;

        /** @var list<array{brand_name: string, brand_slug: string, solves_count: int, puzzles_count: int, brand_puzzles_count: int}> $brandRows */
        $brandRows = $this->database
            ->executeQuery($topBrandsQuery, [
                'piecesCount' => $piecesCount,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        $topBrands = array_map(static function (array $row): PiecesHubBrand {
            return new PiecesHubBrand(
                brandName: $row['brand_name'],
                slug: $row['brand_slug'],
                solvesCount: $row['solves_count'],
                puzzlesCount: $row['puzzles_count'],
                brandPuzzlesCount: $row['brand_puzzles_count'],
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
}
