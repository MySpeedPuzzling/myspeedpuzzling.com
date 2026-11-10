<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\AchievementSqlRules;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;

/**
 * AchievementSqlRules::COMPETITOR_EVENTS as it was before it started from the player's own rows (two LATERAL lookups
 * per public in-person competition), kept verbatim so CompetitorEventsParityTest can prove the rewrite returns the same
 * competitions with the same `at`.
 */
readonly final class CompetitorEventsBeforeOwnRows
{
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
        . ' AND ' . IsCompetitionPubliclyVisible::SQL_CONDITION . ' AND ' . AchievementSqlRules::IS_COMPETITION_KIND;
}
