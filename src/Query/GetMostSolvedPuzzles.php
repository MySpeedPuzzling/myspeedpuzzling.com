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
SELECT count(puzzle_solving_time.puzzle_id) AS solved_count, puzzle.name AS puzzle_name, AVG(puzzle_solving_time.seconds_to_solve) AS average_time, MIN(puzzle_solving_time.seconds_to_solve) AS fastest_time, puzzle.pieces_count
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
GROUP BY puzzle_solving_time.puzzle_id, puzzle.name, puzzle.pieces_count
ORDER BY solved_count DESC
LIMIT 10
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function(array $row): MostSolvedPuzzle {
            /**
             * @var array{
             *     solved_count: int,
             *     puzzle_name: string,
             *     pieces_count: int,
             *     average_time: string,
             *     fastest_time: int,
             * } $row
             */

            return MostSolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
