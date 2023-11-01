<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class GetPuzzlesOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PuzzleOverview>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT puzzle.name AS puzzle_name, puzzle.pieces_count, manufacturer.name AS manufacturer_name, AVG(puzzle_solving_time.seconds_to_solve) AS average_time, COUNT(puzzle_solving_time.id) AS solved_count, MIN(puzzle_solving_time.seconds_to_solve) AS fastest_time
FROM puzzle
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE approved = true
GROUP BY puzzle.name, puzzle.pieces_count, manufacturer.name
HAVING MIN(puzzle_solving_time.seconds_to_solve) > 0
ORDER BY puzzle.name ASC
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function(array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_name: string,
             *     manufacturer_name: string,
             *     pieces_count: int,
             *     average_time: string,
             *     fastest_time: int,
             *     solved_count: int
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }
}
