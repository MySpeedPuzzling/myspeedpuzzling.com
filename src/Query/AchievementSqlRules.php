<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * The rules of the achievements that read more than a result row (Night Owl, Competitor, Online Competitor), written
 * once: GetPlayerStatsSnapshot counts with them and the history replay (AchievementFirstMetDates) dates with them, so
 * both see the same rows (docs/features/badges.md).
 */
readonly final class AchievementSqlRules
{
    /**
     * Joins the competition a result is linked to and its series - or, for a series-level result (a series pick
     * without an edition), that series. Requires alias `pst` = puzzle_solving_time.
     */
    public const string RESULT_COMPETITION_JOINS = <<<SQL
LEFT JOIN competition c ON c.id = pst.competition_id
LEFT JOIN competition_series cs ON cs.id = COALESCE(c.series_id, pst.competition_series_id)
SQL;

    /**
     * A competition, not a workshop, a casual meetup or a swap: no kind set (a plain competition) or one of its kinds
     * is a competition. An edition without kinds of its own has its series' (docs/features/events-page/event-types.md).
     * Requires aliases `c` and `cs` = its series.
     */
    public const string IS_COMPETITION_KIND = <<<SQL
(CASE WHEN jsonb_array_length(c.event_types) > 0 THEN c.event_types ELSE COALESCE(cs.event_types, '[]'::jsonb) END = '[]'::jsonb
 OR CASE WHEN jsonb_array_length(c.event_types) > 0 THEN c.event_types ELSE COALESCE(cs.event_types, '[]'::jsonb) END @> '["competition"]'::jsonb)
SQL;

    /**
     * Night Owl: the player's own result, logged between 22:00 and 04:00 in their time zone on the day they finished
     * it - logged right after finishing, so the logging moment is the solve moment. Players without a time zone
     * have none. Requires alias `pst` and `owning_player` = the player's row.
     */
    public const string IS_NIGHT_OWL_RESULT = <<<SQL
(pst.player_id = owning_player.id
 AND owning_player.timezone IS NOT NULL
 AND pst.finished_at IS NOT NULL
 AND CAST((pst.tracked_at AT TIME ZONE 'UTC') AT TIME ZONE owning_player.timezone AS date) = CAST(pst.finished_at AS date)
 AND EXTRACT(HOUR FROM (pst.tracked_at AT TIME ZONE 'UTC') AT TIME ZONE owning_player.timezone) NOT BETWEEN 4 AND 21)
SQL;

    /**
     * Online Competitor: a result linked to a public online competition, or to a public online series without an
     * edition. Most online events are one undated entry holding every session, so each result counts as one online
     * event. Requires the joins of RESULT_COMPETITION_JOINS.
     */
    public const string IS_ONLINE_COMPETITION_RESULT = '((c.id IS NOT NULL AND c.is_online = true AND '
        . IsCompetitionPubliclyVisible::SQL_CONDITION . ' AND ' . self::IS_COMPETITION_KIND . ')'
        . ' OR (c.id IS NULL AND cs.id IS NOT NULL AND cs.is_online = true AND ' . IsSeriesPubliclyVisible::SQL_CONDITION
        . " AND (cs.event_types = '[]'::jsonb OR cs.event_types @> '[\"competition\"]'::jsonb)))";

    /**
     * Competitor: one row per public in-person competition that has ended (before :today) and that the player took
     * part in - connected as its participant, or in a result linked to it. `at` = when it counted: the day after it
     * ended, or later when the player was connected or linked a result only afterwards. Binds :playerId and :today.
     */
    public const string COMPETITOR_EVENTS = <<<SQL
SELECT
    CAST(c.id AS TEXT) AS competition_id,
    TO_CHAR(
        GREATEST(CAST(CAST(COALESCE(c.date_to, c.date_from) AS date) + 1 AS timestamp), LEAST(participant.at, result.at)),
        'YYYY-MM-DD HH24:MI:SS.US'
    ) AS at
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS entries, MIN(COALESCE(cp.connected_at, cp.registered_at)) AS at
    FROM competition_participant cp
    WHERE cp.competition_id = c.id AND cp.player_id = :playerId AND cp.deleted_at IS NULL
) participant ON true
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS entries, MIN(pst.tracked_at) AS at
    FROM puzzle_solving_time pst
    WHERE pst.competition_id = c.id
      AND pst.suspicious = false
      AND (
        pst.player_id = :playerId
        OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
      )
) result ON true
WHERE c.is_online = false
  AND COALESCE(c.date_to, c.date_from) IS NOT NULL
  AND CAST(COALESCE(c.date_to, c.date_from) AS date) < CAST(:today AS date)
  AND (participant.entries > 0 OR result.entries > 0)
SQL
        . ' AND ' . IsCompetitionPubliclyVisible::SQL_CONDITION . ' AND ' . self::IS_COMPETITION_KIND;
}
