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
    count(puzzle_solving_time.puzzle_id) AS solved_times,
    AVG(puzzle_solving_time.seconds_to_solve) AS average_time,
    MIN(puzzle_solving_time.seconds_to_solve) AS fastest_time,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
GROUP BY puzzle.id, manufacturer.id
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
             *     puzzle_alternative_name: null|string,
             *     puzzle_image: null|string,
             *     solved_times: int,
             *     pieces_count: int,
             *     average_time: string,
             *     fastest_time: int,
             *     manufacturer_name: string,
             * } $row
             */

            return MostSolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
