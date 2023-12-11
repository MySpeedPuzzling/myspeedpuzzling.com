<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\FastestPlayer;

readonly final class GetFastestPlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<FastestPlayer>
     */
    public function perPiecesCount(int $piecesCount, int $howManyPlayers): array
    {
        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    MIN(puzzle_solving_time.seconds_to_solve) AS time,
    player.name AS player_name,
    player.id AS player_id,
    COUNT(puzzle_solving_time.puzzle_id) AS solved_times
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
WHERE puzzle.pieces_count = :piecesCount
    AND puzzle_solving_time.team IS NULL
    AND player.name IS NOT NULL
GROUP BY player.id, puzzle.id
ORDER BY time ASC
LIMIT :howManyPlayers
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCount' => $piecesCount,
                'howManyPlayers' => $howManyPlayers,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): FastestPlayer {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_image: null|string,
             *     time: int,
             *     player_name: string,
             *     player_id: string,
             *     solved_times: int,
             * } $row
             */

            return FastestPlayer::fromDatabaseRow($row);
        }, $data);
    }
}
