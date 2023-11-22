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
SELECT puzzle.id AS puzzle_id, puzzle.name AS puzzle_name, puzzle.image AS puzzle_image, puzzle.alternative_name AS puzzle_alternative_name, puzzle.pieces_count, manufacturer.name AS manufacturer_name, COUNT(puzzle_solving_time.id) AS solved_times, AVG(puzzle_solving_time.seconds_to_solve) AS average_time, MIN(puzzle_solving_time.seconds_to_solve) AS fastest_time
FROM puzzle
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE puzzle.approved = true
GROUP BY puzzle.name, puzzle.pieces_count, manufacturer.name, puzzle.alternative_name, puzzle.id
ORDER BY puzzle.name ASC, manufacturer_name ASC, pieces_count ASC
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function(array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     pieces_count: int,
             *     average_time: null|string,
             *     fastest_time: null|int,
             *     solved_times: int
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }
}
