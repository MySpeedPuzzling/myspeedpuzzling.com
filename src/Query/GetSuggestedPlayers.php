<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\SuggestedPlayer;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\SuggestionReason;

/**
 * "Suggested for you" on the Players page (docs/features/players-page/README.md): people to follow, each with the
 * first reason that applies, in this order:
 *
 * 1. CoPuzzler - shares a pair/team with somebody the viewer puzzled with (a team with a result), but was never in a
 *    pair/team with the viewer. Named only when that co-puzzler is visible to the viewer.
 * 2. SameEvent - a connected participant of a publicly visible competition the viewer is a participant of too; the
 *    most recent one is named.
 * 3. SimilarTime - best 500-piece solo time (community_player_stats) within SIMILAR_TIME_PERCENT of the viewer's.
 *    Never for players who opted out of rankings - it is a comparison of speed, like FindSimilarSpeedPuzzler.
 * 4. PuzzlesInCommon - at least MIN_SHARED_PUZZLES of the same puzzles logged (tracked times on both sides). Counting
 *    that against everybody costs ~280 ms for a heavy puzzler (every solver of every puzzle they logged), so it is only
 *    counted for a daily pool of POOL_SIZE active puzzlers with enough results, same country first: an index-only
 *    count per pool member on (player_id, puzzle_id).
 *
 * Never the viewer, their favorites, private profiles (public-only for everybody, like every Players page list) or a
 * block in either direction - explicit `user_block` rows for the viewer id, as in FindSimilarSpeedPuzzler: suggesting
 * somebody who blocked you is a safety issue. HiddenPlayers is applied on top, like everywhere.
 *
 * Order: active people first (a result in the last ACTIVE_DAYS), then the reasons interleaved (the best of each reason,
 * then the second of each...) so one reason does not fill every card; within a reason same country first, then a
 * shuffle seeded by the day and the viewer - the selection holds for the whole day instead of jumping on every reload.
 *
 * One statement. Reads the precomputed community_player_stats and small per-viewer index ranges; it never aggregates
 * over puzzle_solving_time beyond the viewer's own times and the pool's.
 */
readonly final class GetSuggestedPlayers
{
    public const int LIMIT = 8;

    public const int ACTIVE_DAYS = 60;

    public const int SIMILAR_TIME_PERCENT = 7;

    public const int MIN_SHARED_PUZZLES = 10;

    public const int POOL_SIZE = 30;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * @return list<SuggestedPlayer>
     */
    public function forViewer(string $viewerPlayerId, int $limit = self::LIMIT): array
    {
        if (Uuid::isValid($viewerPlayerId) === false) {
            return [];
        }

        $viewerId = strtolower($viewerPlayerId);
        $now = $this->clock->now();

        $coPuzzler = SuggestionReason::CoPuzzler->priority();
        $sameEvent = SuggestionReason::SameEvent->priority();
        $similarTime = SuggestionReason::SimilarTime->priority();
        $puzzlesInCommon = SuggestionReason::PuzzlesInCommon->priority();
        $competitionVisible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $mineGoing = CompetitionParticipantGoing::sql('mine');
        $participantGoing = CompetitionParticipantGoing::sql('participant');
        $hiddenCoPuzzler = $this->hiddenPlayers->sqlExclude('co_player.id');
        $hidden = $this->hiddenPlayers->sqlExclude('player.id');

        $query = <<<SQL
WITH viewer AS MATERIALIZED (
    SELECT
        player.id,
        player.country,
        COALESCE(player.favorite_players::JSONB, '[]'::JSONB) AS favorites,
        stats.best500_seconds,
        COALESCE(stats.solved_total, 0) AS solved_total
    FROM player
    LEFT JOIN community_player_stats stats ON stats.player_id = player.id
    WHERE player.id = :viewerId
),
my_teams AS MATERIALIZED (
    SELECT
        member.team_id,
        EXISTS (SELECT 1 FROM puzzle_solving_time result WHERE result.puzzling_team_id = member.team_id) AS has_results
    FROM puzzling_team_member member
    WHERE member.player_id = :viewerId
),
my_people AS MATERIALIZED (
    SELECT other.player_id, BOOL_OR(my_teams.has_results) AS puzzled_together
    FROM my_teams
    INNER JOIN puzzling_team_member other ON other.team_id = my_teams.team_id
    WHERE other.player_id IS NOT NULL AND other.player_id <> :viewerId
    GROUP BY other.player_id
),
via_co_puzzler AS (
    SELECT DISTINCT ON (candidate.player_id)
        candidate.player_id,
        co_player.name AS via_player_name,
        co_player.code AS via_player_code,
        (
            co_player.is_private = false
            AND NOT EXISTS (SELECT 1 FROM user_block WHERE user_block.blocker_id = :viewerId AND user_block.blocked_id = co_player.id)
            AND NOT EXISTS (SELECT 1 FROM user_block WHERE user_block.blocker_id = co_player.id AND user_block.blocked_id = :viewerId)
            {$hiddenCoPuzzler}
        ) AS via_visible
    FROM my_people co
    INNER JOIN player co_player ON co_player.id = co.player_id
    INNER JOIN puzzling_team_member co_member ON co_member.player_id = co.player_id
    INNER JOIN puzzling_team_member candidate ON candidate.team_id = co_member.team_id
    WHERE co.puzzled_together
        AND candidate.player_id IS NOT NULL
        AND candidate.player_id <> co.player_id
        AND candidate.player_id <> :viewerId
        AND NOT EXISTS (SELECT 1 FROM my_people known WHERE known.player_id = candidate.player_id)
        AND EXISTS (SELECT 1 FROM puzzle_solving_time result WHERE result.puzzling_team_id = co_member.team_id)
    ORDER BY candidate.player_id, via_visible DESC, MD5(:seed || co_player.id::TEXT)
),
my_events AS MATERIALIZED (
    SELECT DISTINCT mine.competition_id
    FROM competition_participant mine
    WHERE mine.player_id = :viewerId AND {$mineGoing}
),
at_event AS (
    SELECT DISTINCT ON (participant.player_id)
        participant.player_id,
        c.name AS competition_name,
        cs.name AS competition_series_name
    FROM my_events
    INNER JOIN competition c ON c.id = my_events.competition_id
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    INNER JOIN competition_participant participant ON participant.competition_id = c.id
    WHERE participant.player_id IS NOT NULL
        AND participant.player_id <> :viewerId
        AND {$participantGoing}
        AND {$competitionVisible}
    ORDER BY participant.player_id, c.date_from DESC NULLS LAST, c.id
),
similar_time AS (
    SELECT stats.player_id, stats.best500_seconds
    FROM viewer
    INNER JOIN community_player_stats stats
        ON stats.best500_seconds BETWEEN viewer.best500_seconds * (100 - :similarPercent) / 100.0
            AND viewer.best500_seconds * (100 + :similarPercent) / 100.0
    WHERE stats.player_id <> viewer.id
),
my_puzzles AS MATERIALIZED (
    SELECT DISTINCT mine.puzzle_id
    FROM viewer
    INNER JOIN puzzle_solving_time mine ON mine.player_id = viewer.id
    WHERE viewer.solved_total >= :minShared
),
pool AS MATERIALIZED (
    SELECT stats.player_id
    FROM viewer
    INNER JOIN community_player_stats stats
        ON stats.solved_total >= :minShared
            AND stats.last_solved_at >= CAST(:activeSince AS TIMESTAMP)
            AND stats.player_id <> viewer.id
    INNER JOIN player ON player.id = stats.player_id AND player.is_private = false
    WHERE viewer.solved_total >= :minShared
    ORDER BY (player.country = viewer.country) IS TRUE DESC, MD5(:seed || stats.player_id::TEXT)
    LIMIT :poolSize
),
in_common AS (
    SELECT pool.player_id, shared.puzzles AS shared_puzzles
    FROM pool
    CROSS JOIN LATERAL (
        SELECT COUNT(DISTINCT theirs.puzzle_id) AS puzzles
        FROM puzzle_solving_time theirs
        WHERE theirs.player_id = pool.player_id
            AND theirs.puzzle_id IN (SELECT my_puzzles.puzzle_id FROM my_puzzles)
    ) shared
    WHERE shared.puzzles >= :minShared
),
reasons AS (
    SELECT player_id, {$coPuzzler} AS reason, via_player_name, via_player_code, via_visible,
        NULL AS competition_name, NULL AS competition_series_name, NULL::INT AS best500_seconds, NULL::BIGINT AS shared_puzzles
    FROM via_co_puzzler
    UNION ALL
    SELECT player_id, {$sameEvent}, NULL, NULL, NULL, competition_name, competition_series_name, NULL, NULL
    FROM at_event
    UNION ALL
    SELECT player_id, {$similarTime}, NULL, NULL, NULL, NULL, NULL, best500_seconds, NULL
    FROM similar_time
    UNION ALL
    SELECT player_id, {$puzzlesInCommon}, NULL, NULL, NULL, NULL, NULL, NULL, shared_puzzles
    FROM in_common
),
first_reason AS (
    SELECT DISTINCT ON (reasons.player_id) reasons.*
    FROM reasons
    ORDER BY reasons.player_id, reasons.reason
),
suggested AS (
    SELECT
        first_reason.*,
        player.name AS player_name,
        player.code AS player_code,
        player.avatar AS player_avatar,
        player.country AS player_country,
        COALESCE(stats.last_solved_at >= CAST(:activeSince AS TIMESTAMP), false) AS active,
        (player.country = viewer.country) IS TRUE AS same_country
    FROM first_reason
    CROSS JOIN viewer
    INNER JOIN player ON player.id = first_reason.player_id
    LEFT JOIN community_player_stats stats ON stats.player_id = player.id
    WHERE player.is_private = false
        AND (first_reason.reason <> {$similarTime} OR player.ranking_opted_out = false)
        AND NOT viewer.favorites @> JSONB_BUILD_ARRAY(player.id::TEXT)
        AND NOT EXISTS (SELECT 1 FROM user_block WHERE user_block.blocker_id = viewer.id AND user_block.blocked_id = player.id)
        AND NOT EXISTS (SELECT 1 FROM user_block WHERE user_block.blocker_id = player.id AND user_block.blocked_id = viewer.id)
        {$hidden}
),
ranked AS (
    SELECT
        suggested.*,
        ROW_NUMBER() OVER (
            PARTITION BY suggested.reason
            ORDER BY suggested.active DESC, suggested.same_country DESC, MD5(:seed || suggested.player_id::TEXT)
        ) AS reason_position
    FROM suggested
)
SELECT
    player_id,
    player_name,
    player_code,
    player_avatar,
    player_country,
    reason,
    via_player_name,
    via_player_code,
    via_visible,
    competition_name,
    competition_series_name,
    best500_seconds,
    shared_puzzles,
    active
FROM ranked
ORDER BY active DESC, reason_position, reason
LIMIT :limit
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'viewerId' => $viewerId,
                // Stable for the viewer for the whole day, different for every viewer
                'seed' => $now->format('Y-m-d') . '|' . $viewerId,
                'activeSince' => $now->modify('-' . self::ACTIVE_DAYS . ' days')->format('Y-m-d H:i:s'),
                'similarPercent' => self::SIMILAR_TIME_PERCENT,
                'minShared' => self::MIN_SHARED_PUZZLES,
                'poolSize' => self::POOL_SIZE,
                'limit' => $limit,
            ], [
                'similarPercent' => ParameterType::INTEGER,
                'minShared' => ParameterType::INTEGER,
                'poolSize' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ])
            ->fetchAllAssociative();

        return array_map(
            static function (array $row): SuggestedPlayer {
                /**
                 * @var array{
                 *     player_id: string,
                 *     player_name: null|string,
                 *     player_code: string,
                 *     player_avatar: null|string,
                 *     player_country: null|string,
                 *     reason: int|string,
                 *     via_player_name: null|string,
                 *     via_player_code: null|string,
                 *     via_visible: null|bool,
                 *     competition_name: null|string,
                 *     competition_series_name: null|string,
                 *     best500_seconds: null|int|string,
                 *     shared_puzzles: null|int|string,
                 *     active: bool,
                 * } $row
                 */
                return SuggestedPlayer::fromDatabaseRow($row);
            },
            $rows,
        );
    }
}
