<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Value\Puzzler;

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
    public function soloByPuzzleId(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    puzzle_solving_time.seconds_to_solve AS time
FROM puzzle_solving_time
INNER JOIN player ON puzzle_solving_time.player_id = player.id
WHERE puzzle_solving_time.puzzle_id = :puzzleId
    AND player.name IS NOT NULL
    AND puzzle_solving_time.team IS NULL
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
             * } $row
             */

            return PuzzleSolver::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @throws PuzzleNotFound
     * @return array<PuzzleSolversGroup>
     */
    public function groupsByPuzzleId(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $query = <<<SQL
SELECT
    pst.player_id AS added_by_player_id,
    pst.puzzle_id AS puzzle_id,
    pst.seconds_to_solve AS time,
    comment,
    pst.team ->> 'team_id' AS team_id,
    JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem ->> 'player_id',
            'player_name', COALESCE(p.name, player_elem ->> 'player_name')
        )
    ) AS players
FROM
    puzzle_solving_time pst,
    LATERAL json_array_elements(pst.team -> 'puzzlers') AS player_elem
    LEFT JOIN player p ON p.id = (player_elem ->> 'player_id')::UUID
WHERE
    pst.puzzle_id = :puzzleId
GROUP BY
    pst.id, time
ORDER BY time ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PuzzleSolversGroup {
            /**
             * @var array{
             *     added_by_player_id: string,
             *     puzzle_id: string,
             *     time: int,
             *     comment: null|string,
             *     team_id: null|string,
             *     players: string,
             * } $row
             */

            return PuzzleSolversGroup::fromDatabaseRow($row);
        }, $data);
    }
}
