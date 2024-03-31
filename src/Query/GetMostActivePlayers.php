<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MostActivePlayer;

readonly final class GetMostActivePlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function mostActivePlayersQuery(): string
    {
        return <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.country AS player_country,
    p.code AS player_code,
    COUNT(DISTINCT subquery.puzzle_id || '-' || subquery.player_id) as solved_puzzles_count
FROM (
    SELECT
        pst.id as puzzle_id,
        pst.player_id
    FROM
        puzzle_solving_time pst

    UNION

    SELECT
        pst.id as puzzle_id,
        (json_array_elements_text(pst.team->'puzzlers')::json->>'player_id')::uuid as player_id
    FROM
        puzzle_solving_time pst
        CROSS JOIN LATERAL json_array_elements(pst.team->'puzzlers')
    WHERE
        pst.team IS NOT NULL
) as subquery
JOIN player p ON subquery.player_id = p.id
GROUP BY p.id, p.name, p.country
ORDER BY solved_puzzles_count DESC
LIMIT :limit
SQL;
    }

    /**
     * @return array<MostActivePlayer>
     */
    public function mostActiveSoloPlayers(int $limit): array
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    player.country AS player_country,
    player.code AS player_code,
    COUNT(puzzle_solving_time.id) as solved_puzzles_count,
    SUM(puzzle.pieces_count) as total_pieces_count,
    SUM(puzzle_solving_time.seconds_to_solve) as total_seconds
FROM puzzle_solving_time
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN puzzle ON puzzle_solving_time.puzzle_id = puzzle.id
WHERE player.name IS NOT NULL
    AND puzzle_solving_time.team IS NULL
GROUP BY player.id
ORDER BY solved_puzzles_count DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): MostActivePlayer {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     solved_puzzles_count: int,
             *     total_pieces_count: int,
             *     total_seconds: int,
             * } $row
             */

            return MostActivePlayer::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<MostActivePlayer>
     */
    public function mostActiveSoloPlayersInMonth(int $limit, int $month, int $year): array
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    player.country AS player_country,
    player.code AS player_code,
    COUNT(puzzle_solving_time.id) as solved_puzzles_count,
    SUM(puzzle.pieces_count) as total_pieces_count,
    SUM(puzzle_solving_time.seconds_to_solve) as total_seconds
FROM puzzle_solving_time
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN puzzle ON puzzle_solving_time.puzzle_id = puzzle.id
WHERE player.name IS NOT NULL
    AND puzzle_solving_time.team IS NULL
    AND EXTRACT(MONTH FROM puzzle_solving_time.finished_at) = :month
    AND EXTRACT(YEAR FROM puzzle_solving_time.finished_at) = :year
GROUP BY player.id
ORDER BY solved_puzzles_count DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'month' => $month,
                'year' => $year,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): MostActivePlayer {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     solved_puzzles_count: int,
             *     total_pieces_count: int,
             *     total_seconds: int,
             * } $row
             */

            return MostActivePlayer::fromDatabaseRow($row);
        }, $data);
    }
}
