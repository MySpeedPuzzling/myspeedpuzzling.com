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
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.player_id = player.id AND puzzle_solving_time.puzzling_type = 'solo'
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
WITH player_times AS (
    SELECT pst.id, pst.puzzle_id, pst.seconds_to_solve
    FROM puzzle_solving_time pst
    WHERE pst.puzzling_type = 'duo'
      AND pst.team IS NOT NULL
      AND (pst.team->'puzzlers')::jsonb @> jsonb_build_array(jsonb_build_object('player_id', :playerId))
)
SELECT
    player.id AS player_id,
    player.name AS player_name,
    COALESCE(SUM(pt.seconds_to_solve), 0) AS total_seconds,
    COALESCE(COUNT(pt.id), 0) AS solved_puzzles_count,
    COALESCE(SUM(puzzle.pieces_count), 0) AS total_pieces
FROM player
LEFT JOIN player_times pt ON true
LEFT JOIN puzzle ON puzzle.id = pt.puzzle_id
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
WITH player_times AS (
    SELECT pst.id, pst.puzzle_id, pst.seconds_to_solve
    FROM puzzle_solving_time pst
    WHERE pst.puzzling_type = 'team'
      AND pst.team IS NOT NULL
      AND (pst.team->'puzzlers')::jsonb @> jsonb_build_array(jsonb_build_object('player_id', :playerId))
)
SELECT
    player.id AS player_id,
    player.name AS player_name,
    COALESCE(SUM(pt.seconds_to_solve), 0) AS total_seconds,
    COALESCE(COUNT(pt.id), 0) AS solved_puzzles_count,
    COALESCE(SUM(puzzle.pieces_count), 0) AS total_pieces
FROM player
LEFT JOIN player_times pt ON true
LEFT JOIN puzzle ON puzzle.id = pt.puzzle_id
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
