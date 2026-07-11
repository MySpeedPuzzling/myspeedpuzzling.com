<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Results\BrandHubStats;
use SpeedPuzzling\Web\Results\PiecesMedian;

readonly final class GetBrandHub
{
    /**
     * A per-pieces median is only shown when it is backed by at least this
     * many solo solves.
     */
    private const int MIN_SOLVES_PER_PIECES_BUCKET = 10;

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
        // skew it.
        $solvesQuery = <<<SQL
SELECT
    COUNT(*) AS solves_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve)
        FILTER (WHERE pst.puzzlers_count = 1) AS median_seconds
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
WHERE puzzle.manufacturer_id = :brandId
    AND pst.seconds_to_solve IS NOT NULL
SQL;

        /** @var array{solves_count: int, median_seconds: null|float|string} $solvesRow */
        $solvesRow = (array) $this->database
            ->executeQuery($solvesQuery, [
                'brandId' => $brand['brand_id'],
            ])
            ->fetchAssociative();

        $piecesMediansQuery = <<<SQL
SELECT
    puzzle.pieces_count,
    COUNT(*) AS solves_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS median_seconds
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
WHERE puzzle.manufacturer_id = :brandId
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.puzzlers_count = 1
GROUP BY puzzle.pieces_count
HAVING COUNT(*) >= :minSolves
ORDER BY COUNT(*) DESC
LIMIT 8
SQL;

        /** @var list<array{pieces_count: int, solves_count: int, median_seconds: float|string}> $piecesRows */
        $piecesRows = $this->database
            ->executeQuery($piecesMediansQuery, [
                'brandId' => $brand['brand_id'],
                'minSolves' => self::MIN_SOLVES_PER_PIECES_BUCKET,
            ])
            ->fetchAllAssociative();

        $piecesMedians = array_map(static function (array $row): PiecesMedian {
            return new PiecesMedian(
                piecesCount: $row['pieces_count'],
                solvesCount: $row['solves_count'],
                medianSeconds: (int) round((float) $row['median_seconds']),
            );
        }, $piecesRows);

        return new BrandHubStats(
            brandId: $brand['brand_id'],
            brandName: $brand['brand_name'],
            slug: $brand['brand_slug'],
            approved: $brand['brand_approved'],
            puzzlesCount: $puzzlesCount,
            solvesCount: $solvesRow['solves_count'],
            medianSeconds: $solvesRow['median_seconds'] !== null ? (int) round((float) $solvesRow['median_seconds']) : null,
            piecesMedians: $piecesMedians,
        );
    }
}
