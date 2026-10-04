<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\DifficultyRankingBrand;
use SpeedPuzzling\Web\Results\DifficultyRankingEntry;
use SpeedPuzzling\Web\Value\DifficultyRankingDirection;
use SpeedPuzzling\Web\Value\MetricConfidence;

/**
 * Read model of the public hardest / easiest puzzle lists.
 *
 * A puzzle is ranked when it is approved, not hidden and has a difficulty
 * score of medium or high confidence (at least 10 qualified solvers). Ties
 * are broken by the sample size (more solvers first), then by name.
 */
readonly final class GetDifficultyRankings
{
    private const string RATED_PUZZLE_CONDITION = <<<SQL
puzzle.approved = true
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    AND puzzle_difficulty.difficulty_score IS NOT NULL
    AND puzzle_difficulty.confidence IN (:confidences)
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int> $piecesCounts
     *
     * @return array<int, int> piece count => rated puzzles, only counts with at least $minimumPuzzles, ascending
     */
    public function ratedPuzzlesPerPieces(array $piecesCounts, int $minimumPuzzles): array
    {
        if ($piecesCounts === []) {
            return [];
        }

        $condition = self::RATED_PUZZLE_CONDITION;

        $query = <<<SQL
SELECT
    puzzle.pieces_count,
    COUNT(*) AS rated_puzzles
FROM puzzle_difficulty
INNER JOIN puzzle ON puzzle.id = puzzle_difficulty.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle.pieces_count IN (:piecesCounts)
    AND {$condition}
GROUP BY puzzle.pieces_count
HAVING COUNT(*) >= :minimumPuzzles
ORDER BY puzzle.pieces_count
SQL;

        /** @var list<array{pieces_count: int|string, rated_puzzles: int|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'piecesCounts' => $piecesCounts,
                'minimumPuzzles' => $minimumPuzzles,
            ] + $this->ratedPuzzleParameters(), [
                'piecesCounts' => ArrayParameterType::INTEGER,
                'confidences' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['pieces_count']] = (int) $row['rated_puzzles'];
        }

        return $counts;
    }

    /**
     * Approved brands with a slug (their hub URL) and at least $minimumPuzzles rated puzzles.
     *
     * @return list<DifficultyRankingBrand> most rated puzzles first
     */
    public function brandsWithRatedPuzzles(int $minimumPuzzles): array
    {
        $condition = self::RATED_PUZZLE_CONDITION;

        $query = <<<SQL
SELECT
    manufacturer.id AS brand_id,
    manufacturer.name AS brand_name,
    manufacturer.slug AS brand_slug,
    COUNT(*) AS rated_puzzles
FROM puzzle_difficulty
INNER JOIN puzzle ON puzzle.id = puzzle_difficulty.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE manufacturer.approved = true
    AND manufacturer.slug IS NOT NULL
    AND {$condition}
GROUP BY manufacturer.id, manufacturer.name, manufacturer.slug
HAVING COUNT(*) >= :minimumPuzzles
ORDER BY COUNT(*) DESC, manufacturer.name ASC
SQL;

        /** @var list<array{brand_id: string, brand_name: string, brand_slug: string, rated_puzzles: int|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'minimumPuzzles' => $minimumPuzzles,
            ] + $this->ratedPuzzleParameters(), [
                'confidences' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): DifficultyRankingBrand {
            return new DifficultyRankingBrand(
                brandId: $row['brand_id'],
                brandName: $row['brand_name'],
                slug: $row['brand_slug'],
                ratedPuzzlesCount: (int) $row['rated_puzzles'],
            );
        }, $rows);
    }

    /**
     * @return list<DifficultyRankingEntry>
     */
    public function byPieces(int $piecesCount, DifficultyRankingDirection $direction, int $limit): array
    {
        return $this->ranking('puzzle.pieces_count = :piecesCount', [
            'piecesCount' => $piecesCount,
        ], $direction, $limit);
    }

    /**
     * @return list<DifficultyRankingEntry>
     */
    public function byBrand(string $brandId, DifficultyRankingDirection $direction, int $limit): array
    {
        return $this->ranking('puzzle.manufacturer_id = :brandId', [
            'brandId' => $brandId,
        ], $direction, $limit);
    }

    /**
     * @param array<string, int|string> $parameters
     *
     * @return list<DifficultyRankingEntry>
     */
    private function ranking(string $scope, array $parameters, DifficultyRankingDirection $direction, int $limit): array
    {
        $condition = self::RATED_PUZZLE_CONDITION;
        $scoreOrder = $direction->scoreOrder();

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name,
    puzzle_statistics.solved_times_solo_count AS solo_solves_count,
    puzzle_statistics.median_time_solo,
    puzzle_difficulty.difficulty_score,
    puzzle_difficulty.difficulty_tier
FROM puzzle_difficulty
INNER JOIN puzzle ON puzzle.id = puzzle_difficulty.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
WHERE {$scope}
    AND {$condition}
ORDER BY puzzle_difficulty.difficulty_score {$scoreOrder}, puzzle_difficulty.sample_size DESC, puzzle.name ASC, puzzle.id ASC
LIMIT :limit
SQL;

        $rows = $this->database
            ->executeQuery($query, $parameters + [
                'limit' => $limit,
            ] + $this->ratedPuzzleParameters(), [
                'confidences' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): DifficultyRankingEntry {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|float|string,
             *     pieces_count: int|string,
             *     manufacturer_name: string,
             *     solo_solves_count: null|int|string,
             *     median_time_solo: null|int|string,
             *     difficulty_score: float|string,
             *     difficulty_tier: null|int|string,
             * } $row
             */

            return DifficultyRankingEntry::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * @return array{now: string, confidences: list<string>}
     */
    private function ratedPuzzleParameters(): array
    {
        return [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'confidences' => [MetricConfidence::Medium->value, MetricConfidence::High->value],
        ];
    }
}
