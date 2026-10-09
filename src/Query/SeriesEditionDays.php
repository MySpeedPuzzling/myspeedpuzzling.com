<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * Per-series day facts of its publicly visible editions, as one LEFT JOIN LATERAL for a query listing series (alias
 * `cs` = competition_series): the add-time picker's series option (docs/features/events-page/high-frequency-series.md
 * "The default list") and API v1's series list. An edition's days are the ones the picker has always used:
 * COALESCE(date_from, date_to, its first round's start) .. COALESCE(date_to, date_from, its first round's start).
 *
 * Columns on the alias: edition_count (publicly visible editions), dated_edition_count (those of them with a day),
 * has_live (an edition whose days hold today),
 * last_past_day (the first day of the latest edition that is over - NULL when none), next_day (the first day of the
 * soonest edition that starts after today - NULL when none). Undated editions only count.
 */
readonly final class SeriesEditionDays
{
    /**
     * @param string $todayParameter a parameter holding today's date ('Y-m-d'), e.g. ':today'
     */
    public static function sqlJoin(string $alias = 'sed', string $todayParameter = ':today'): string
    {
        // The visibility condition's `cs` is the outer series row - the series of every edition here
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        return <<<SQL
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS edition_count,
        COUNT(ed.day_from) AS dated_edition_count,
        COALESCE(bool_or(ed.day_from IS NOT NULL AND CAST({$todayParameter} AS DATE) BETWEEN ed.day_from AND ed.day_to), false) AS has_live,
        MAX(ed.day_from) FILTER (WHERE ed.day_to < CAST({$todayParameter} AS DATE)) AS last_past_day,
        MIN(ed.day_from) FILTER (WHERE ed.day_from > CAST({$todayParameter} AS DATE)) AS next_day
    FROM (
        SELECT CAST(COALESCE(c.date_from, c.date_to, r.first_round_at) AS DATE) AS day_from,
            CAST(COALESCE(c.date_to, c.date_from, r.first_round_at) AS DATE) AS day_to
        FROM competition c
        LEFT JOIN LATERAL (
            SELECT MIN(cr.starts_at) AS first_round_at
            FROM competition_round cr
            WHERE cr.competition_id = c.id
        ) r ON true
        WHERE c.series_id = cs.id
            AND {$visible}
    ) ed
) {$alias} ON true
SQL;
    }
}
