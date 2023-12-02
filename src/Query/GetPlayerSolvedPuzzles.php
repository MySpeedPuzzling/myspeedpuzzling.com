<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class GetPlayerSolvedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byTimeId(string $timeId): SolvedPuzzle
    {
        if (Uuid::isValid($timeId) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    pieces_count,
    group_name,
    players_count,
    player.name AS player_name,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle_solving_time.id = :timeId
SQL;

        /**
         * @var null|array{
         *     time_id: string,
         *     player_id: string,
         *     player_name: null|string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     manufacturer_name: string,
         *     puzzle_image: null|string,
         *     players_count: int,
         *     time: int,
         *     pieces_count: int,
         *     group_name: null|string,
         *     comment: null|string
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'timeId' => $timeId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        return SolvedPuzzle::fromDatabaseRow($row);

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
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    pieces_count,
    group_name,
    players_count,
    player.name AS player_name,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
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
             *     time_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     players_count: int,
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
