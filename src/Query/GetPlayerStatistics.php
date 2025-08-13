<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerStatistics;

readonly final class GetPlayerStatistics
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function solo(string $playerId): PlayerStatistics
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    COALESCE(SUM(puzzle_solving_time.seconds_to_solve), 0) AS total_seconds,
    COALESCE(COUNT(puzzle_solving_time.id), 0) AS solved_puzzles_count,
    COALESCE(SUM(puzzle.pieces_count), 0) AS total_pieces
FROM player
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.player_id = player.id AND puzzle_solving_time.team IS NULL
LEFT JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE
    player.id = :playerId
GROUP BY
    player.id, player.name;
SQL;

        /**
         * @var false|array{
         *     player_id: string,
         *     player_name: null|string,
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerStatistics::fromDatabaseRow($row);
    }

    /**
     * @throws PlayerNotFound
     */
    public function duo(string $playerId): PlayerStatistics
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    COALESCE(SUM(puzzle_solving_time.seconds_to_solve), 0) AS total_seconds,
    COALESCE(COUNT(puzzle.id), 0) AS solved_puzzles_count,
    COALESCE(SUM(puzzle.pieces_count), 0) AS total_pieces
FROM player
LEFT JOIN puzzle_solving_time ON EXISTS (
        SELECT 1
        FROM json_array_elements(puzzle_solving_time.team->'puzzlers') AS team_player
        WHERE (team_player->>'player_id')::UUID = player.id
            AND puzzle_solving_time.team IS NOT NULL
            AND json_array_length(team -> 'puzzlers') = 2
    )
LEFT JOIN puzzle ON puzzle_solving_time.puzzle_id = puzzle.id
WHERE
    player.id = :playerId
GROUP BY
    player.id;
SQL;

        /**
         * @var false|array{
         *     player_id: string,
         *     player_name: null|string,
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerStatistics::fromDatabaseRow($row);
    }

    /**
     * @throws PlayerNotFound
     */
    public function team(string $playerId): PlayerStatistics
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    COALESCE(SUM(puzzle_solving_time.seconds_to_solve), 0) AS total_seconds,
    COALESCE(COUNT(puzzle.id), 0) AS solved_puzzles_count,
    COALESCE(SUM(puzzle.pieces_count), 0) AS total_pieces
FROM player
LEFT JOIN puzzle_solving_time ON EXISTS (
        SELECT 1
        FROM json_array_elements(puzzle_solving_time.team->'puzzlers') AS team_player
        WHERE (team_player->>'player_id')::UUID = player.id
            AND puzzle_solving_time.team IS NOT NULL
            AND json_array_length(team -> 'puzzlers') > 2
    )
LEFT JOIN puzzle ON puzzle_solving_time.puzzle_id = puzzle.id
WHERE
    player.id = :playerId
GROUP BY
    player.id;
SQL;

        /**
         * @var false|array{
         *     player_id: string,
         *     player_name: null|string,
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerStatistics::fromDatabaseRow($row);
    }
}
