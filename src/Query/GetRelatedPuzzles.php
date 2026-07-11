<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\RelatedPuzzle;

readonly final class GetRelatedPuzzles
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Most-solved approved puzzles from the same manufacturer, excluding the
     * currently displayed one. Feeds the "More {brand} puzzles" module on the
     * puzzle detail page.
     *
     * @return list<RelatedPuzzle>
     */
    public function byManufacturer(string $manufacturerId, string $excludePuzzleId, int $limit): array
    {
        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.pieces_count,
    COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times
FROM puzzle
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
WHERE puzzle.manufacturer_id = :manufacturerId
    AND puzzle.id != :excludePuzzleId
    AND puzzle.approved = true
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
ORDER BY solved_times DESC, puzzle.name ASC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'manufacturerId' => $manufacturerId,
                'excludePuzzleId' => $excludePuzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): RelatedPuzzle {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     pieces_count: int,
             *     solved_times: int,
             * } $row
             */

            return RelatedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
