<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;

readonly final class GetPlayerSolvedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     */
    public function byTimeId(string $timeId): SolvedPuzzleDetail
    {
        if (Uuid::isValid($timeId) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    pieces_count,
    player.name AS player_name,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    manufacturer.id AS manufacturer_id,
    finished_at,
    finished_puzzle_photo,
    first_attempt,
    CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN
            JSON_AGG(
                JSON_BUILD_OBJECT(
                    'player_id', player_elem.player ->> 'player_id',
                    'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                    'player_code', p.code,
                    'player_country', p.country
                ) ORDER BY player_elem.ordinality
            )
        ELSE NULL
    END AS players
FROM puzzle_solving_time
    LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON puzzle_solving_time.team IS NOT NULL
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle_solving_time.id = :timeId
GROUP BY puzzle_solving_time.id, puzzle.id, player.id, manufacturer.id
SQL;

        /**
         * @var false|array{
         *     time_id: string,
         *     team_id: null|string,
         *     player_id: string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     manufacturer_name: string,
         *     manufacturer_id: string,
         *     puzzle_image: null|string,
         *     time: int,
         *     pieces_count: int,
         *     comment: null|string,
         *     players: null|string,
         *     finished_at: string,
         *     finished_puzzle_photo: string,
         *     first_attempt: bool,
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

        return SolvedPuzzleDetail::fromDatabaseRow($row);

    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function soloByPlayerId(string $playerId): array
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
    player.name AS player_name,
    player.country AS player_country,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.comment,
    puzzle_solving_time.tracked_at,
    finished_at,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    first_attempt
FROM puzzle_solving_time
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    puzzle_solving_time.player_id = :playerId
    AND puzzle_solving_time.team IS NULL
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
    public function duoByPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS player_id,
    pieces_count,
    finished_puzzle_photo,
    tracked_at,
    finished_at,
    puzzle.identification_number AS puzzle_identification_number,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    pst.team ->> 'team_id' AS team_id,
    first_attempt,
    JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
            'player_country', p.country
        ) ORDER BY player_elem.ordinality
    ) AS players
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id,
LATERAL json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE
    EXISTS (
        SELECT 1
        FROM json_array_elements(pst.team -> 'puzzlers') AS sub_player_elem
        WHERE (sub_player_elem ->> 'player_id')::UUID = :playerId::UUID
    )
    AND json_array_length(team -> 'puzzlers') = 2
GROUP BY
    pst.id, puzzle.id, manufacturer.id, time
ORDER BY time ASC
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
             *     team_id: null|string,
             *     player_id: string,
             *     player_name: null,
             *     player_country: null,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     players: string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     tracked_at: string,
             *     finished_at: string,
             *     first_attempt: bool,
             * } $row
             */

            $row['player_name'] = null;
            $row['player_country'] = null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function teamByPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS player_id,
    pieces_count,
    finished_puzzle_photo,
    tracked_at,
    finished_at,
    puzzle.identification_number AS puzzle_identification_number,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    pst.team ->> 'team_id' AS team_id,
    first_attempt,
    JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
            'player_country', p.country
        ) ORDER BY player_elem.ordinality
    ) AS players
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id,
LATERAL json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE
    EXISTS (
        SELECT 1
        FROM json_array_elements(pst.team -> 'puzzlers') AS sub_player_elem
        WHERE (sub_player_elem ->> 'player_id')::UUID = :playerId::UUID
    )
    AND json_array_length(team -> 'puzzlers') > 2
GROUP BY
    pst.id, puzzle.id, manufacturer.id, time
ORDER BY time ASC
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
             *     team_id: null|string,
             *     player_id: string,
             *     player_name: null,
             *     player_country: null,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     players: string,
             *     finished_puzzle_photo: null|string,
             *     puzzle_identification_number: null|string,
             *     tracked_at: string,
             *     finished_at: string,
             *     first_attempt: bool,
             * } $row
             */

            $row['player_name'] = null;
            $row['player_country'] = null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
