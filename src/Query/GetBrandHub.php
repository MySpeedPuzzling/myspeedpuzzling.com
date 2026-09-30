<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\PiecesPuzzlesController;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Results\BrandHubStats;
use SpeedPuzzling\Web\Results\BrandPiecesHubStats;
use SpeedPuzzling\Web\Results\PiecesMedian;

readonly final class GetBrandHub
{
    /**
     * A per-pieces median is only shown when it is backed by at least this
     * many solo solves.
     */
    private const int MIN_SOLVES_PER_PIECES_BUCKET = 10;

    private const int MAX_PIECES_MEDIANS = 8;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    public function bySlug(string $slug): BrandHubStats
    {
        $brandQuery = <<<SQL
SELECT
    manufacturer.id AS brand_id,
    manufacturer.name AS brand_name,
    manufacturer.slug AS brand_slug,
    manufacturer.approved AS brand_approved
FROM manufacturer
WHERE manufacturer.slug = :slug
SQL;

        /**
         * @var false|array{
         *     brand_id: string,
         *     brand_name: string,
         *     brand_slug: string,
         *     brand_approved: bool,
         * } $brand
         */
        $brand = $this->database
            ->executeQuery($brandQuery, [
                'slug' => $slug,
            ])
            ->fetchAssociative();

        if (is_array($brand) === false) {
            throw new ManufacturerNotFound();
        }

        $puzzlesCountQuery = <<<SQL
SELECT COUNT(*)
FROM puzzle
WHERE puzzle.manufacturer_id = :brandId
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
SQL;

        $puzzlesCount = $this->database
            ->executeQuery($puzzlesCountQuery, [
                'brandId' => $brand['brand_id'],
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchOne();
        assert(is_int($puzzlesCount));

        // Solves count includes every recorded time (solo/duo/team); the
        // median is computed over solo solves only so group times do not
        // skew it. One pass over the brand's solves gives the rows per piece
        // count (the "median by piece count" list, the brand × pieces pages)
        // and, through the empty grouping set, the brand's total - a row that
        // comes even without a single solve. For Ravensburger (60 % of all
        // solves) that is half the time of two passes.
        $solvesQuery = <<<SQL
SELECT
    puzzle.pieces_count,
    GROUPING(puzzle.pieces_count) = 1 AS is_brand_total,
    COUNT(*) AS solves_count,
    COUNT(*) FILTER (WHERE pst.puzzlers_count = 1) AS solo_solves_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve)
        FILTER (WHERE pst.puzzlers_count = 1) AS median_seconds
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
WHERE puzzle.manufacturer_id = :brandId
    AND pst.seconds_to_solve IS NOT NULL
GROUP BY GROUPING SETS ((puzzle.pieces_count), ())
SQL;

        /** @var list<array{pieces_count: null|int, is_brand_total: bool, solves_count: int, solo_solves_count: int, median_seconds: null|float|string}> $solvesRows */
        $solvesRows = $this->database
            ->executeQuery($solvesQuery, [
                'brandId' => $brand['brand_id'],
            ])
            ->fetchAllAssociative();

        $solvesRow = ['solves_count' => 0, 'median_seconds' => null];
        $solvesPerPieces = [];

        foreach ($solvesRows as $row) {
            if ($row['is_brand_total']) {
                $solvesRow = $row;

                continue;
            }

            assert($row['pieces_count'] !== null);

            $solvesPerPieces[] = [
                'pieces_count' => $row['pieces_count'],
                'solves_count' => $row['solves_count'],
                'solo_solves_count' => $row['solo_solves_count'],
                'median_seconds' => $row['median_seconds'],
            ];
        }

        $piecesPagesQuery = <<<SQL
SELECT
    puzzle.pieces_count,
    COUNT(*) AS puzzles_count
FROM puzzle
WHERE puzzle.manufacturer_id = :brandId
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    AND puzzle.pieces_count IN (:allowedPieces)
GROUP BY puzzle.pieces_count
ORDER BY puzzle.pieces_count
SQL;

        /** @var list<array{pieces_count: int, puzzles_count: int}> $puzzlesPerPieces */
        $puzzlesPerPieces = $this->database
            ->executeQuery($piecesPagesQuery, [
                'brandId' => $brand['brand_id'],
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'allowedPieces' => PiecesPuzzlesController::ALLOWED_PIECES,
            ], [
                'allowedPieces' => ArrayParameterType::INTEGER,
            ])
            ->fetchAllAssociative();

        return new BrandHubStats(
            brandId: $brand['brand_id'],
            brandName: $brand['brand_name'],
            slug: $brand['brand_slug'],
            approved: $brand['brand_approved'],
            puzzlesCount: $puzzlesCount,
            solvesCount: $solvesRow['solves_count'],
            medianSeconds: self::roundedSeconds($solvesRow['median_seconds']),
            piecesMedians: self::piecesMedians($solvesPerPieces),
            piecesPages: self::piecesPages($puzzlesPerPieces, $solvesPerPieces),
        );
    }

    /**
     * The most-solved piece counts (by solo solves) whose median is backed by
     * at least MIN_SOLVES_PER_PIECES_BUCKET solo solves.
     *
     * @param list<array{pieces_count: int, solves_count: int, solo_solves_count: int, median_seconds: null|float|string}> $solvesPerPieces
     * @return list<PiecesMedian>
     */
    private static function piecesMedians(array $solvesPerPieces): array
    {
        $buckets = array_values(array_filter(
            $solvesPerPieces,
            static fn (array $row): bool => $row['solo_solves_count'] >= self::MIN_SOLVES_PER_PIECES_BUCKET,
        ));

        usort($buckets, static fn (array $a, array $b): int => [$b['solo_solves_count'], $a['pieces_count']] <=> [$a['solo_solves_count'], $b['pieces_count']]);

        return array_map(static function (array $row): PiecesMedian {
            return new PiecesMedian(
                piecesCount: $row['pieces_count'],
                solvesCount: $row['solo_solves_count'],
                medianSeconds: (int) self::roundedSeconds($row['median_seconds']),
            );
        }, array_slice($buckets, 0, self::MAX_PIECES_MEDIANS));
    }

    /**
     * @param list<array{pieces_count: int, puzzles_count: int}> $puzzlesPerPieces
     * @param list<array{pieces_count: int, solves_count: int, solo_solves_count: int, median_seconds: null|float|string}> $solvesPerPieces
     * @return list<BrandPiecesHubStats>
     */
    private static function piecesPages(array $puzzlesPerPieces, array $solvesPerPieces): array
    {
        $solvesByPieces = array_column($solvesPerPieces, null, 'pieces_count');

        return array_map(static function (array $row) use ($solvesByPieces): BrandPiecesHubStats {
            $solves = $solvesByPieces[$row['pieces_count']] ?? null;

            return new BrandPiecesHubStats(
                piecesCount: $row['pieces_count'],
                puzzlesCount: $row['puzzles_count'],
                solvesCount: $solves !== null ? $solves['solves_count'] : 0,
                medianSeconds: $solves !== null ? self::roundedSeconds($solves['median_seconds']) : null,
            );
        }, $puzzlesPerPieces);
    }

    private static function roundedSeconds(null|float|string $seconds): null|int
    {
        return $seconds !== null ? (int) round((float) $seconds) : null;
    }
}
