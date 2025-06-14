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
        private GetTeamPlayers $getTeamPlayers,
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
    is_private
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    (puzzle_solving_time.player_id = :playerId OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID))))
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        // TODO: optimize, filter out rows without teams
        /** @var array<string> $timeIds */
        $timeIds = array_column($data, 'time_id');

        $players = $this->getTeamPlayers->byIds($timeIds);

        return array_map(static function(array $row) use ($players): SolvedPuzzle {
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
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             *     is_private: bool,
             * } $row
             */

            $row['players'] = $players[$row['time_id']] ?? null;

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
    is_private
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE player.name IS NOT NULL
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();


        // TODO: optimize, filter out rows without teams
        /** @var array<string> $timeIds */
        $timeIds = array_column($data, 'time_id');

        $players = $this->getTeamPlayers->byIds($timeIds);

        return array_map(static function(array $row) use ($players): SolvedPuzzle {
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
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             *     is_private: bool,
             * } $row
             */

            $row['players'] = $players[$row['time_id']] ?? null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function ofPlayerFavorites(int $limit, string $playerId): array
    {
        $query = <<<SQL
WITH favorite_player_ids_array AS (
    SELECT array_agg(fav_players.player_id::UUID) AS favorite_ids
    FROM player
    CROSS JOIN LATERAL json_array_elements_text(favorite_players) AS fav_players(player_id)
    WHERE id = :playerId
),
filtered_puzzle_solving_time AS (
    SELECT
        pst.id
    FROM
        puzzle_solving_time pst, favorite_player_ids_array fpi
    WHERE
        pst.player_id = ANY(fpi.favorite_ids)
        OR (
            pst.team IS NOT NULL
            AND EXISTS (
                SELECT 1
                FROM jsonb_array_elements(pst.team::jsonb -> 'puzzlers') AS player_elem(player)
                WHERE (player_elem.player ->> 'player_id')::UUID = ANY(fpi.favorite_ids)
            )
        )
    ORDER BY pst.tracked_at DESC
    LIMIT :limit
)
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS player_id,
    player.name AS player_name,
    player.country AS player_country,
    puzzle.pieces_count,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    pst.tracked_at AS tracked_at,
    pst.finished_at,
    pst.finished_puzzle_photo AS finished_puzzle_photo,
    pst.team ->> 'team_id' AS team_id,
    first_attempt,
    is_private
FROM
    filtered_puzzle_solving_time fpt
INNER JOIN puzzle_solving_time pst ON pst.id = fpt.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN player ON pst.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
ORDER BY pst.tracked_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'playerId' => $playerId
            ])
            ->fetchAllAssociative();

        // TODO: optimize, filter out rows without teams
        /** @var array<string> $timeIds */
        $timeIds = array_column($data, 'time_id');

        $players = $this->getTeamPlayers->byIds($timeIds);

        return array_map(static function(array $row) use ($players): SolvedPuzzle {
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
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             *     is_private: bool,
             * } $row
             */

            $row['players'] = $players[$row['time_id']] ?? null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
