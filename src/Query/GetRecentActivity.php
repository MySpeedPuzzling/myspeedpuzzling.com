<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\RecentActivityItem;

readonly final class GetRecentActivity
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<RecentActivityItem>
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
    player.code AS player_code,
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
    puzzle_solving_time.unboxed,
    is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    CASE WHEN puzzle_solving_time.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', p.is_private
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID)
    ELSE NULL END AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
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

        return array_map(static function (array $row): RecentActivityItem {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: null|int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     team_id: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     is_private: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     players: null|string,
             * } $row
             */

            return RecentActivityItem::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<RecentActivityItem>
     */
    public function latest(int $limit): array
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
    player.code AS player_code,
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
    puzzle_solving_time.unboxed,
    is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    CASE WHEN puzzle_solving_time.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', p.is_private
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID)
    ELSE NULL END AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
WHERE player.is_private = false
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): RecentActivityItem {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: null|int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     team_id: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     is_private: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     players: null|string,
             * } $row
             */

            return RecentActivityItem::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<RecentActivityItem>
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
    player.code AS player_code,
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
    pst.unboxed,
    is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    CASE WHEN pst.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', p.is_private
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID)
    ELSE NULL END AS players
FROM
    filtered_puzzle_solving_time fpt
INNER JOIN puzzle_solving_time pst ON pst.id = fpt.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN player ON pst.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON competition.id = pst.competition_id
WHERE is_private = false
ORDER BY pst.tracked_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): RecentActivityItem {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: null|int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             *     team_id: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     is_private: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     players: null|string,
             * } $row
             */

            return RecentActivityItem::fromDatabaseRow($row);
        }, $data);
    }
}
