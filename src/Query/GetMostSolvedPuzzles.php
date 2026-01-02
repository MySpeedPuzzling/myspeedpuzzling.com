<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MostSolvedPuzzle;

readonly final class GetMostSolvedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<MostSolvedPuzzle>
     */
    public function top(int $howManyPuzzles): array
    {
        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.image AS puzzle_image,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle_statistics.solved_times_count AS solved_times,
    puzzle_statistics.average_time_solo,
    puzzle_statistics.fastest_time_solo,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name
FROM puzzle_statistics
INNER JOIN puzzle ON puzzle.id = puzzle_statistics.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle_statistics.solved_times_count > 0
ORDER BY solved_times DESC
LIMIT :howManyPuzzles
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'howManyPuzzles' => $howManyPuzzles,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): MostSolvedPuzzle {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_image: null|string,
             *     solved_times: int,
             *     pieces_count: int,
             *     average_time_solo: string,
             *     fastest_time_solo: int,
             *     manufacturer_name: string,
             * } $row
             */

            return MostSolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<MostSolvedPuzzle>
     */
    public function topInMonth(int $limit, int $month, int $year): array
    {
        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.image AS puzzle_image,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    count(puzzle_solving_time.puzzle_id) AS solved_times,
    AVG(CASE WHEN team IS NULL THEN seconds_to_solve END) AS average_time_solo,
    MIN(CASE WHEN team IS NULL THEN seconds_to_solve END) AS fastest_time_solo,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE EXTRACT(MONTH FROM puzzle_solving_time.tracked_at) = :month
  AND EXTRACT(YEAR FROM puzzle_solving_time.tracked_at) = :year
GROUP BY puzzle.id, manufacturer.id
ORDER BY solved_times DESC
LIMIT :howManyPuzzles
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'howManyPuzzles' => $limit,
                'month' => $month,
                'year' => $year,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): MostSolvedPuzzle {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_image: null|string,
             *     solved_times: int,
             *     pieces_count: int,
             *     average_time_solo: null|string,
             *     fastest_time_solo: null|int,
             *     manufacturer_name: string,
             * } $row
             */

            return MostSolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
