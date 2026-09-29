<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\PiecesPuzzlesController;
use SpeedPuzzling\Web\Results\BrandDirectoryEntry;
use SpeedPuzzling\Web\Results\BrandHubStats;
use SpeedPuzzling\Web\Results\BrandPiecesHubStats;

/**
 * The indexable catalogue pages: brand hubs for the A–Z directory and the
 * sitemap, brand × pieces pages for the sitemap. Counts use the definitions of
 * GetBrandHub (visible puzzles, solves with a time) and the rules live in
 * BrandHubStats / BrandPiecesHubStats, so a page's robots meta, the directory
 * and the sitemap cannot disagree.
 */
readonly final class GetBrandDirectory
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Every brand whose hub is indexable, sorted by name (accents ignored).
     *
     * @return list<BrandDirectoryEntry>
     */
    public function indexableBrands(): array
    {
        $query = <<<SQL
SELECT
    manufacturer.name AS brand_name,
    manufacturer.slug AS brand_slug,
    manufacturer.approved AS brand_approved,
    upper(left(immutable_unaccent(manufacturer.name), 1)) AS first_letter,
    puzzles.puzzles_count,
    solves.solves_count
FROM manufacturer
INNER JOIN (
    SELECT puzzle.manufacturer_id, COUNT(*) AS puzzles_count
    FROM puzzle
    WHERE puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp
    GROUP BY puzzle.manufacturer_id
) puzzles ON puzzles.manufacturer_id = manufacturer.id
INNER JOIN (
    SELECT puzzle.manufacturer_id, COUNT(*) AS solves_count
    FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE pst.seconds_to_solve IS NOT NULL
    GROUP BY puzzle.manufacturer_id
) solves ON solves.manufacturer_id = manufacturer.id
WHERE manufacturer.approved = true
    AND manufacturer.slug IS NOT NULL
    AND puzzles.puzzles_count >= :minPuzzles
ORDER BY lower(immutable_unaccent(manufacturer.name)), manufacturer.slug
SQL;

        /** @var list<array{brand_name: string, brand_slug: string, brand_approved: bool, first_letter: string, puzzles_count: int, solves_count: int}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'minPuzzles' => BrandHubStats::MIN_INDEXABLE_PUZZLES,
            ])
            ->fetchAllAssociative();

        $entries = [];

        foreach ($rows as $row) {
            if (BrandHubStats::isIndexableBrand($row['brand_approved'], $row['puzzles_count'], $row['solves_count']) === false) {
                continue;
            }

            $entries[] = new BrandDirectoryEntry(
                brandName: $row['brand_name'],
                slug: $row['brand_slug'],
                letter: preg_match('/^[A-Z]$/', $row['first_letter']) === 1 ? $row['first_letter'] : BrandDirectoryEntry::OTHER_LETTER,
                puzzlesCount: $row['puzzles_count'],
                solvesCount: $row['solves_count'],
            );
        }

        return $entries;
    }

    /**
     * Every indexable brand × pieces page as [brand slug, piece count].
     *
     * @return list<array{slug: string, pieces: int}>
     */
    public function indexableBrandPiecesPages(): array
    {
        $query = <<<SQL
WITH brand_puzzles AS (
    SELECT puzzle.manufacturer_id, COUNT(*) AS puzzles_count
    FROM puzzle
    WHERE puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp
    GROUP BY puzzle.manufacturer_id
),
combination_puzzles AS (
    SELECT puzzle.manufacturer_id, puzzle.pieces_count, COUNT(*) AS puzzles_count
    FROM puzzle
    WHERE (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
        AND puzzle.pieces_count IN (:allowedPieces)
    GROUP BY puzzle.manufacturer_id, puzzle.pieces_count
    HAVING COUNT(*) >= :minCombinationPuzzles
),
combination_solves AS (
    SELECT puzzle.manufacturer_id, puzzle.pieces_count, COUNT(*) AS solves_count
    FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE pst.seconds_to_solve IS NOT NULL
        AND puzzle.pieces_count IN (:allowedPieces)
    GROUP BY puzzle.manufacturer_id, puzzle.pieces_count
)
SELECT
    manufacturer.slug AS brand_slug,
    manufacturer.approved AS brand_approved,
    brand_puzzles.puzzles_count AS brand_puzzles_count,
    combination_puzzles.pieces_count,
    combination_puzzles.puzzles_count,
    combination_solves.solves_count
FROM combination_puzzles
INNER JOIN combination_solves
    ON combination_solves.manufacturer_id = combination_puzzles.manufacturer_id
    AND combination_solves.pieces_count = combination_puzzles.pieces_count
INNER JOIN manufacturer ON manufacturer.id = combination_puzzles.manufacturer_id
INNER JOIN brand_puzzles ON brand_puzzles.manufacturer_id = manufacturer.id
WHERE manufacturer.approved = true
    AND manufacturer.slug IS NOT NULL
ORDER BY manufacturer.slug, combination_puzzles.pieces_count
SQL;

        /** @var list<array{brand_slug: string, brand_approved: bool, brand_puzzles_count: int, pieces_count: int, puzzles_count: int, solves_count: int}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'allowedPieces' => PiecesPuzzlesController::ALLOWED_PIECES,
                'minCombinationPuzzles' => BrandPiecesHubStats::MIN_INDEXABLE_PUZZLES,
            ], [
                'allowedPieces' => ArrayParameterType::INTEGER,
            ])
            ->fetchAllAssociative();

        $pages = [];

        foreach ($rows as $row) {
            // A combination's solves are a subset of the brand's solves, so they
            // stand in for the brand's "at least one solve" condition.
            $brandHubIndexable = BrandHubStats::isIndexableBrand($row['brand_approved'], $row['brand_puzzles_count'], $row['solves_count']);

            if (BrandPiecesHubStats::isIndexableCombination($brandHubIndexable, $row['puzzles_count'], $row['solves_count'])) {
                $pages[] = [
                    'slug' => $row['brand_slug'],
                    'pieces' => $row['pieces_count'],
                ];
            }
        }

        return $pages;
    }
}
