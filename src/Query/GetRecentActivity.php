<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\SkillTier;

readonly final class GetRecentActivity
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
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

        $notHidden = $this->notHidden('puzzle_solving_time');

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.tracked_at AS tracked_at,
    finished_at,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    first_attempt,
    puzzle_solving_time.unboxed,
    {$this->privateProfileAccess->sqlIsPrivate('player')} AS is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    ps.skill_tier,
    player.ranking_opted_out,
    CASE WHEN puzzle_solving_time.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', {$this->privateProfileAccess->sqlIsPrivate('p')},
            'skill_tier', ps_m.skill_tier,
            'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID
        LEFT JOIN player_skill ps_m ON ps_m.player_id = p.id)
    ELSE NULL END AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
LEFT JOIN competition_series cs ON cs.id = competition.series_id
LEFT JOIN player_skill ps ON ps.player_id = player.id
WHERE
    (puzzle_solving_time.player_id = :playerId OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID))))
    {$notHidden}
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
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
             *     puzzle_image_ratio: null|string,
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
             *     competition_series_name: null|string,
             *     competition_series_shortcut: null|string,
             *     competition_series_slug: null|string,
             *     players: null|string,
             *     skill_tier: null|int,
             *     ranking_opted_out: bool,
             * } $row
             */

            $row['skill_tier_name'] = $row['skill_tier'] !== null
                ? strtolower(SkillTier::from((int) $row['skill_tier'])->name)
                : null;

            return RecentActivityItem::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<RecentActivityItem>
     */
    public function latest(int $limit): array
    {
        $notHidden = $this->notHidden('puzzle_solving_time');

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.tracked_at AS tracked_at,
    finished_at,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    first_attempt,
    puzzle_solving_time.unboxed,
    {$this->privateProfileAccess->sqlIsPrivate('player')} AS is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    ps.skill_tier,
    player.ranking_opted_out,
    CASE WHEN puzzle_solving_time.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', {$this->privateProfileAccess->sqlIsPrivate('p')},
            'skill_tier', ps_m.skill_tier,
            'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID
        LEFT JOIN player_skill ps_m ON ps_m.player_id = p.id)
    ELSE NULL END AS players
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
LEFT JOIN competition_series cs ON cs.id = competition.series_id
LEFT JOIN player_skill ps ON ps.player_id = player.id
WHERE {$this->privateProfileAccess->sqlIsPublic('player')}
    {$notHidden}
ORDER BY puzzle_solving_time.tracked_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
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
             *     puzzle_image_ratio: null|string,
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
             *     competition_series_name: null|string,
             *     competition_series_shortcut: null|string,
             *     competition_series_slug: null|string,
             *     players: null|string,
             *     skill_tier: null|int,
             *     ranking_opted_out: bool,
             * } $row
             */

            $row['skill_tier_name'] = $row['skill_tier'] !== null
                ? strtolower(SkillTier::from((int) $row['skill_tier'])->name)
                : null;

            return RecentActivityItem::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * Times of the player's favorite players, solo or as team members.
     *
     * The favorites are read first and passed in as values, one containment document per favorite:
     * that way the planner sees who they are and picks the plan per viewer. Active favorites fill the
     * limit within the newest few hundred times (walking tracked_at backwards), while for quiet ones
     * the player_id and custom_pst_team_puzzlers_gin indexes fetch their few times directly - when
     * the favorites came from a CTE, every viewer got the backwards walk, through the whole table for
     * favorites without 20 recent times (~390 ms, and an EXISTS over jsonb_array_elements() that no
     * index can answer).
     *
     * @return array<RecentActivityItem>
     */
    public function ofPlayerFavorites(int $limit, string $playerId): array
    {
        /** @var list<string> $favoritePlayerIds */
        $favoritePlayerIds = $this->database
            ->executeQuery(
                'SELECT favorite_player_id::UUID FROM player CROSS JOIN LATERAL json_array_elements_text(favorite_players) AS favorite_player_id WHERE id = :playerId',
                ['playerId' => $playerId],
            )
            ->fetchFirstColumn();

        if ($favoritePlayerIds === []) {
            return [];
        }

        $favoritePuzzlers = array_map(
            static fn (string $favoritePlayerId): string => Json::encode([['player_id' => $favoritePlayerId]]),
            $favoritePlayerIds,
        );

        $notHidden = $this->notHidden('pst');

        $query = <<<SQL
WITH filtered_puzzle_solving_time AS (
    SELECT
        pst.id
    FROM
        puzzle_solving_time pst
    WHERE
        pst.player_id IN (:favoritePlayerIds){$notHidden}
        OR (pst.team IS NOT NULL AND (pst.team::jsonb -> 'puzzlers') @> ANY(ARRAY[:favoritePuzzlers]::jsonb[]){$notHidden})
    ORDER BY pst.tracked_at DESC
    LIMIT :limit
)
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
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
    {$this->privateProfileAccess->sqlIsPrivate('player')} AS is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    ps.skill_tier,
    player.ranking_opted_out,
    CASE WHEN pst.team IS NOT NULL THEN
        (SELECT JSON_AGG(JSON_BUILD_OBJECT(
            'player_id', elem.player ->> 'player_id',
            'player_name', COALESCE(p.name, elem.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'is_private', {$this->privateProfileAccess->sqlIsPrivate('p')},
            'skill_tier', ps_m.skill_tier,
            'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
        ) ORDER BY elem.ordinality)
        FROM json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS elem(player, ordinality)
        LEFT JOIN player p ON p.id = (elem.player ->> 'player_id')::UUID
        LEFT JOIN player_skill ps_m ON ps_m.player_id = p.id)
    ELSE NULL END AS players
FROM
    filtered_puzzle_solving_time fpt
INNER JOIN puzzle_solving_time pst ON pst.id = fpt.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN player ON pst.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON competition.id = pst.competition_id
LEFT JOIN competition_series cs ON cs.id = competition.series_id
LEFT JOIN player_skill ps ON ps.player_id = player.id
WHERE {$this->privateProfileAccess->sqlIsPublic('player')}
ORDER BY pst.tracked_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'limit' => $limit,
                'favoritePlayerIds' => $favoritePlayerIds,
                'favoritePuzzlers' => $favoritePuzzlers,
            ], [
                'favoritePlayerIds' => ArrayParameterType::STRING,
                'favoritePuzzlers' => ArrayParameterType::STRING,
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
             *     puzzle_image_ratio: null|string,
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
             *     competition_series_name: null|string,
             *     competition_series_shortcut: null|string,
             *     competition_series_slug: null|string,
             *     players: null|string,
             *     skill_tier: null|int,
             *     ranking_opted_out: bool,
             * } $row
             */

            $row['skill_tier_name'] = $row['skill_tier'] !== null
                ? strtolower(SkillTier::from((int) $row['skill_tier'])->name)
                : null;

            return RecentActivityItem::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * A row is a solo time or a group time. Solo: hidden with its player. Group: by its members
     * alone - the tracker is one of them, and excluding by tracker as well would take away the
     * viewer's own group times that a hidden player happened to track.
     */
    private function notHidden(string $timeAlias): string
    {
        return $this->hiddenPlayers->sqlExclude("(CASE WHEN {$timeAlias}.team IS NULL THEN {$timeAlias}.player_id END)")
            . $this->hiddenPlayers->sqlExcludeTeam("{$timeAlias}.team");
    }
}
