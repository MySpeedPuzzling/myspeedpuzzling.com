<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\GlobalStatistics;
use SpeedPuzzling\Web\Results\MostActivePlayer;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PlayerStatistics;

readonly final class GetStatistics
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function soloForPlayer(string $playerId): PlayerStatistics
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
    public function inGroupForPlayer(string $playerId): PlayerStatistics
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
    COALESCE(SUM(CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN puzzle.pieces_count / json_array_length(puzzle_solving_time.team->'puzzlers')
        ELSE 0
    END), 0) AS total_pieces
FROM player
LEFT JOIN puzzle_solving_time ON EXISTS (
        SELECT 1
        FROM json_array_elements(puzzle_solving_time.team->'puzzlers') AS team_player
        WHERE (team_player->>'player_id')::UUID = player.id
        AND puzzle_solving_time.team IS NOT NULL
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

    public function globally(): GlobalStatistics
    {
        $query = <<<SQL
SELECT
    SUM(puzzle_solving_time.seconds_to_solve) AS total_seconds,
    COUNT(puzzle_solving_time.id) AS solved_puzzles_count,
    SUM(puzzle.pieces_count) AS total_pieces
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
SQL;

        /**
         * @var array{
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: null|int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query)
            ->fetchAssociative();

        return GlobalStatistics::fromDatabaseRow($row);
    }

    public function globallyInMonth(int $month, int $year): GlobalStatistics
    {
        $query = <<<SQL
SELECT
    SUM(puzzle_solving_time.seconds_to_solve) AS total_seconds,
    COUNT(puzzle_solving_time.id) AS solved_puzzles_count,
    SUM(puzzle.pieces_count) AS total_pieces
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE EXTRACT(MONTH FROM puzzle_solving_time.tracked_at) = :month
    AND EXTRACT(YEAR FROM puzzle_solving_time.tracked_at) = :year
SQL;

        /**
         * @var array{
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: null|int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'month' => $month,
                'year' => $year,
            ])
            ->fetchAssociative();

        return GlobalStatistics::fromDatabaseRow($row);
    }
}
