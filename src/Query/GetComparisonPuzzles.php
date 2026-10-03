<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ComparisonPuzzle;

/**
 * Display data of the puzzles on the shown page of a comparison (ComparisonResult::$pagePuzzleIds, ≤ 50 per "Show
 * more" step) - one statement, whatever the line-up. A puzzle hidden until a future date is left out; a hidden image
 * is masked.
 */
readonly final class GetComparisonPuzzles
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, ComparisonPuzzle> keyed by puzzle id, in the order of $puzzleIds
     */
    public function byIds(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_filter(
            array_map(strtolower(...), $puzzleIds),
            static fn(string $puzzleId): bool => Uuid::isValid($puzzleId),
        )));

        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name,
    puzzle.alternative_name,
    manufacturer.name AS manufacturer_name,
    puzzle.pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > CAST(:now AS TIMESTAMP) THEN NULL ELSE puzzle.image END AS image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > CAST(:now AS TIMESTAMP) THEN NULL ELSE puzzle.image_ratio END AS image_ratio
FROM puzzle
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle.id IN (:puzzleIds)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= CAST(:now AS TIMESTAMP))
SQL;

        /**
         * @var list<array{
         *     puzzle_id: string,
         *     name: string,
         *     alternative_name: null|string,
         *     manufacturer_name: null|string,
         *     pieces_count: int,
         *     image: null|string,
         *     image_ratio: null|float|string,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['puzzleIds' => $puzzleIds, 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['puzzleIds' => ArrayParameterType::STRING],
        );

        $byId = [];

        foreach ($rows as $row) {
            $byId[strtolower($row['puzzle_id'])] = new ComparisonPuzzle(
                puzzleId: $row['puzzle_id'],
                name: $row['name'],
                alternativeName: $row['alternative_name'],
                manufacturerName: $row['manufacturer_name'],
                piecesCount: (int) $row['pieces_count'],
                image: $row['image'],
                imageRatio: $row['image_ratio'] !== null ? (float) $row['image_ratio'] : null,
            );
        }

        $ordered = [];

        foreach ($puzzleIds as $puzzleId) {
            if (isset($byId[$puzzleId])) {
                $ordered[$puzzleId] = $byId[$puzzleId];
            }
        }

        return $ordered;
    }
}
