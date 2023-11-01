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
SELECT puzzle.name AS puzzle_name, puzzle_solving_time.seconds_to_solve AS time, player.name AS player_name
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
WHERE puzzle.pieces_count = 500
AND puzzle_solving_time.players_count = 1
ORDER BY seconds_to_solve ASC
LIMIT 10
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function(array $row): FastestPlayer {
            /** @var array{puzzle_name: string, time: int, player_name: string} $row */

            return FastestPlayer::fromDatabaseRow($row);
        }, $data);
    }
}
