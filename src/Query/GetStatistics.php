<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerStatistics;

readonly final class GetStatistics
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PlayerStatistics>
     */
    public function mostActivePlayers(int $limit): array
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    SUM(puzzle_solving_time.seconds_to_solve) AS total_seconds,
    SUM(puzzle.pieces_count) AS total_pieces,
    COUNT(puzzle_solving_time.id) AS solved_puzzles_count
FROM puzzle_solving_time
INNER JOIN player ON player.id = puzzle_solving_time.player_id
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE
    player.name IS NOT NULL
GROUP BY
    player.id,
    player.name
ORDER BY total_seconds DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PlayerStatistics {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: null|string,
             *     total_seconds: int,
             *     total_pieces: int,
             *     solved_puzzles_count: int,
             * } $row
             */

            return PlayerStatistics::fromDatabaseRow($row);
        }, $data);
    }
}
