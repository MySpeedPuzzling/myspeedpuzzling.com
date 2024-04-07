<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class GetLastSolvedPuzzle
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<SolvedPuzzle>
     */
    public function forPlayer(string $playerId, int $limit): array
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
    player.name AS player_name,
    player.country AS player_country,
    pieces_count,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.tracked_at AS tracked_at,
    finished_at,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    first_attempt,
    CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN JSON_AGG(
            JSON_BUILD_OBJECT(
                'player_id', player_elem.player ->> 'player_id',
                'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                'player_country', p.country
            ) ORDER BY player_elem.ordinality
        )
    END AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON true
LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE
    (puzzle_solving_time.player_id = :playerId OR EXISTS (
        SELECT 1
        FROM json_array_elements(puzzle_solving_time.team -> 'puzzlers') as team_player
        WHERE team_player ->> 'player_id' = :playerId
    ))
GROUP BY puzzle_solving_time.id, puzzle.id, manufacturer.id, time, player.id, puzzle_solving_time.tracked_at
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     team_id: null|string,
             *     players: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function limit(int $limit): array
    {
        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    player.name AS player_name,
    player.country AS player_country,
    pieces_count,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.tracked_at AS tracked_at,
    finished_at,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    first_attempt,
    CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN JSON_AGG(
            JSON_BUILD_OBJECT(
                'player_id', player_elem.player ->> 'player_id',
                'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                'player_country', p.country
            ) ORDER BY player_elem.ordinality
        )
    END AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON true
LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE
    player.name IS NOT NULL
GROUP BY puzzle_solving_time.id, puzzle.id, manufacturer.id, time, player.id
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     team_id: null|string,
             *     players: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function ofPlayers(int $limit, string $playerId): array
    {
        $query = <<<SQL
WITH favorite_player_ids AS (
    SELECT (json_array_elements_text(favorite_players)::UUID) AS player_id
    FROM player
    WHERE id = :playerId
),
filtered_puzzle_solving_time AS (
    SELECT
        puzzle_solving_time.id
    FROM
        puzzle_solving_time
    LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON true
    WHERE
        puzzle_solving_time.player_id IN (SELECT player_id FROM favorite_player_ids)
        OR (player_elem.player ->> 'player_id')::UUID IN (SELECT player_id FROM favorite_player_ids)
    GROUP BY puzzle_solving_time.id, puzzle_solving_time.tracked_at
    ORDER BY puzzle_solving_time.tracked_at DESC
    LIMIT :limit
)
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    player.name AS player_name,
    player.country AS player_country,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.tracked_at AS tracked_at,
    puzzle_solving_time.finished_at,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    first_attempt,
    CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem.player ->> 'player_id',
            'player_name', COALESCE(p_inner.name, player_elem.player ->> 'player_name'),
            'player_country', p_inner.country
        ) ORDER BY player_elem.ordinality
    ) END AS players
FROM
    puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON true
LEFT JOIN player p_inner ON p_inner.id = (player_elem.player ->> 'player_id')::UUID
INNER JOIN filtered_puzzle_solving_time ON puzzle_solving_time.id = filtered_puzzle_solving_time.id
GROUP BY puzzle_solving_time.id, puzzle.id, player.id, manufacturer.id
ORDER BY puzzle_solving_time.tracked_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'playerId' => $playerId
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     team_id: null|string,
             *     players: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
