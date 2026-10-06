<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\SkillTier;

readonly final class GetFastestGroups
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function perPiecesCount(int $piecesCount, int $howManyPlayers, null|CountryCode $countryCode): array
    {
        $notHidden = $this->hiddenPlayers->sqlExcludeTeam('pst.team');

        $countryCondition = $countryCode !== null
            ? 'AND EXISTS (SELECT 1 FROM puzzling_team_member ptm INNER JOIN player member_player ON member_player.id = ptm.player_id WHERE ptm.team_id = team_best.team_id AND member_player.country = :countryCode)'
            : '';

        // One row per team - the exact set of people (puzzling_team), whatever order they were entered in -
        // with its best time. Each team's best by a hash aggregate, the fastest `howManyPlayers` teams that have a public
        // member (and one from the country), then one time with that best per team. Team and country are decided before the
        // LIMIT, so a country sees all of its teams, not only those among the world's fastest. 25-65 ms on prod data
        // (2026-10-06), for the world and for any country. Ties go to the lower team id at the cut-off and to the earliest of
        // a team's equal best times.
        $query = <<<SQL
WITH team_best AS (
    SELECT
        pst.puzzling_team_id AS team_id,
        MIN(pst.seconds_to_solve) AS best_seconds
    FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE puzzle.pieces_count = :piecesCount
        AND pst.puzzling_type = 'team'
        AND pst.seconds_to_solve > 0
        AND pst.suspicious = false
        {$notHidden}
    GROUP BY pst.puzzling_team_id
),
fastest_teams AS (
    SELECT team_best.team_id, team_best.best_seconds
    FROM team_best
    WHERE EXISTS (
            SELECT 1
            FROM puzzling_team_member ptm
            INNER JOIN player member_player ON member_player.id = ptm.player_id
            WHERE ptm.team_id = team_best.team_id
                AND member_player.is_private = false
        )
        {$countryCondition}
    ORDER BY team_best.best_seconds ASC, team_best.team_id
    LIMIT :howManyPlayers
),
candidate_times AS (
    SELECT DISTINCT ON (pst.puzzling_team_id)
        pst.id
    FROM fastest_teams
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = fastest_teams.team_id
        AND pst.seconds_to_solve = fastest_teams.best_seconds
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE puzzle.pieces_count = :piecesCount
        AND pst.puzzling_type = 'team'
        AND pst.suspicious = false
    ORDER BY pst.puzzling_team_id, COALESCE(pst.finished_at, pst.tracked_at), pst.id
),
player_data AS (
    SELECT
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        puzzle.alternative_names AS puzzle_alternative_names,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
        puzzle.pieces_count,
        pst.comment,
        pst.tracked_at,
        pst.finished_at,
        pst.finished_puzzle_photo,
        pst.seconds_to_solve AS time,
        player.name AS player_name,
        player.code AS player_code,
        player.country AS player_country,
        player.id AS player_id,
        manufacturer.name AS manufacturer_name,
        puzzle.identification_number AS puzzle_identification_number,
        pst.id AS time_id,
        pst.puzzling_team_id::varchar AS team_id,
        pst.first_attempt,
        pst.unboxed,
        {$this->privateProfileAccess->sqlIsPrivate('player')} AS is_private,
        competition.id AS competition_id,
        competition.shortcut AS competition_shortcut,
        competition.slug AS competition_slug,
        cs.name AS competition_series_name,
        cs.shortcut AS competition_series_shortcut,
        cs.slug AS competition_series_slug,
        competition.name AS competition_name,
        ps_main.skill_tier,
        player.ranking_opted_out,
        JSON_AGG(
            JSON_BUILD_OBJECT(
                'player_id', player_elem.player ->> 'player_id',
                'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                'player_code', p.code,
                'player_country', p.country,
                'player_avatar', p.avatar,
                'is_private', {$this->privateProfileAccess->sqlIsPrivate('p')},
                'skill_tier', ps_member.skill_tier,
                'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
            ) ORDER BY player_elem.ordinality
        ) AS players
    FROM candidate_times ct
    INNER JOIN puzzle_solving_time pst ON pst.id = ct.id
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    INNER JOIN player ON pst.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN competition ON pst.competition_id = competition.id
    LEFT JOIN competition_series cs ON cs.id = competition.series_id
    LEFT JOIN player_skill ps_main ON ps_main.player_id = player.id,
    LATERAL json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
    LEFT JOIN player_skill ps_member ON ps_member.player_id = p.id
    GROUP BY puzzle.id, player.id, manufacturer.id, pst.id, competition.id, cs.id, ps_main.skill_tier
)
SELECT *
FROM player_data
ORDER BY time ASC, team_id
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCount' => $piecesCount,
                'countryCode' => $countryCode?->name,
                'howManyPlayers' => $howManyPlayers,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SolvedPuzzle {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_names: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     time: int,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     player_id: string,
             *     solved_times: int,
             *     manufacturer_name: string,
             *     time_id: string,
             *     finished_puzzle_photo: null|string,
             *     tracked_at: string,
             *     pieces_count: int,
             *     comment: null|string,
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
             *     skill_tier: null|int,
             *     ranking_opted_out: bool,
             * } $row
             */

            $row['skill_tier_name'] = $row['skill_tier'] !== null
                ? strtolower(SkillTier::from((int) $row['skill_tier'])->name)
                : null;

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
