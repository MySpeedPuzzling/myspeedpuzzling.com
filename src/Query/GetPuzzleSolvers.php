<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleSolver;

readonly final class GetPuzzleSolvers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @return array<PuzzleSolver>
     */
    public function byPuzzleId(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    puzzle_solving_time.seconds_to_solve AS time,
    group_name,
    players_count
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
WHERE puzzle_solving_time.puzzle_id = :puzzleId
    AND player.name IS NOT NULL
ORDER BY seconds_to_solve ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PuzzleSolver {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: string,
             *     time: int,
             *     players_count: int,
             *     group_name: null|string
             * } $row
             */

            return PuzzleSolver::fromDatabaseRow($row);
        }, $data);
    }
}
