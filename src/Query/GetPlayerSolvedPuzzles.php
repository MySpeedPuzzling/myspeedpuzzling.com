<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class GetPlayerSolvedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function byPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT puzzle.id AS puzzle_id, puzzle.name AS puzzle_name, puzzle_solving_time.seconds_to_solve AS time, pieces_count, group_name, players_count 
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
WHERE puzzle_solving_time.player_id = :playerId
ORDER BY seconds_to_solve ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): SolvedPuzzle {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     players_count: int,
             *     time: int,
             *     pieces_count: int,
             *     group_name: null|string,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
