<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\FastestPlayer;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class GetLastSolvedPuzzle
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function limit(int $limit): array
    {
        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    player.name AS player_name,
    pieces_count,
    group_name,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    player.name IS NOT NULL
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     group_name: null|string,
             *     comment: null|string
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
