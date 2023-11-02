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
SELECT puzzle.id AS puzzle_id, count(puzzle_solving_time.puzzle_id) AS solved_times, puzzle.name AS puzzle_name, AVG(puzzle_solving_time.seconds_to_solve) AS average_time, MIN(puzzle_solving_time.seconds_to_solve) AS fastest_time, puzzle.pieces_count
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
GROUP BY puzzle.id, puzzle.name, puzzle.pieces_count
ORDER BY solved_times DESC
LIMIT :howManyPuzzles
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'howManyPuzzles' => $howManyPuzzles,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): MostSolvedPuzzle {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     solved_times: int,
             *     pieces_count: int,
             *     average_time: string,
             *     fastest_time: int,
             * } $row
             */

            return MostSolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
