<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * THE rule that finds the edition of a series pick (docs/features/events-page/high-frequency-series.md "The matching
 * rule") - the only place it is written. SeriesEditionResolver runs it for one time (add/edit handlers, the form's
 * preview), SeriesEditionReconciler over every series pick in scope. Nothing else may restate it.
 *
 * Input per time: series S, puzzle P, category C (puzzling_type: solo/duo/team = competition_round.category), solve
 * day D. Candidates: the publicly visible editions of S (IsCompetitionPubliclyVisible - never a draft, pending or
 * rejected one, never an edition of a hidden series).
 *
 * 1. Puzzle: candidates with a REVEALED round of category C holding P. One -> it. Several -> the one whose day span
 *    is nearest to D (0 inside it); a tie -> not identified (rule 2 is not tried, P5).
 * 2. Date (only when rule 1 has no candidate): candidates whose day span widened by one day each side contains D and
 *    that have no rounds or a round of category C. Exactly one -> it.
 * 3. Else not identified (series-level).
 *
 * A candidate's day span = LEAST(its first date, its first round's local day) .. GREATEST(its last date, its last
 * round's local day), NULLs ignored (an edition dated by one field spans that day, P6); a round's local day is its
 * start in its own zone (COALESCE(timezone, 'Europe/Prague')). Without dates and rounds it has no span: only rule 1 can
 * pick it. A round puzzle is revealed when the round no longer keeps it secret (RoundPuzzleReveal::sqlHidden() - an
 * image-only secret counts as hidden, P7) and the puzzle's own hide_until is over; before that the round still counts
 * as "a round of category C" for rule 2, so a date match never tells which edition holds a hidden puzzle.
 *
 * Every argument is an SQL expression already cast (`CAST(:seriesId AS UUID)`, a column); the reveal needs the
 * parameter NOW_PARAMETER ('Y-m-d H:i:s' from ClockInterface).
 */
readonly final class SeriesEditionMatch
{
    public const string NOW_PARAMETER = 'seriesMatchNow';

    public const string DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Two CTEs to follow WITH: series_match_edition (competition_id, series_id, span_from, span_to, categories - NULL
     * for an edition without rounds) and series_match_round_puzzle (competition_id, series_id, round_id, category,
     * puzzle_id - revealed round puzzles only). $seriesScope narrows the editions, on alias c (competition).
     */
    public static function sqlCandidates(string $seriesScope): string
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $hidden = RoundPuzzleReveal::sqlHidden('crp', 'cr', ':' . self::NOW_PARAMETER);
        $now = ':' . self::NOW_PARAMETER;

        return <<<SQL
series_match_edition AS (
    SELECT c.id AS competition_id,
        c.series_id,
        LEAST(CAST(COALESCE(c.date_from, c.date_to) AS DATE), rd.first_day) AS span_from,
        GREATEST(CAST(COALESCE(c.date_to, c.date_from) AS DATE), rd.last_day) AS span_to,
        rd.categories
    FROM competition c
    INNER JOIN competition_series cs ON cs.id = c.series_id
    LEFT JOIN LATERAL (
        SELECT MIN(CAST((cr.starts_at AT TIME ZONE 'UTC') AT TIME ZONE COALESCE(cr.timezone, 'Europe/Prague') AS DATE)) AS first_day,
            MAX(CAST((cr.starts_at AT TIME ZONE 'UTC') AT TIME ZONE COALESCE(cr.timezone, 'Europe/Prague') AS DATE)) AS last_day,
            array_agg(DISTINCT CAST(cr.category AS VARCHAR)) AS categories
        FROM competition_round cr
        WHERE cr.competition_id = c.id
    ) rd ON true
    WHERE {$seriesScope}
        AND {$visible}
),
series_match_round_puzzle AS (
    SELECT e.competition_id, e.series_id, cr.id AS round_id, CAST(cr.category AS VARCHAR) AS category, crp.puzzle_id
    FROM series_match_edition e
    INNER JOIN competition_round cr ON cr.competition_id = e.competition_id
    INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id
    INNER JOIN puzzle p ON p.id = crp.puzzle_id
    WHERE NOT {$hidden}
        AND (p.hide_until IS NULL OR p.hide_until <= CAST({$now} AS TIMESTAMP))
)
SQL;
    }

    /**
     * One row: competition_id (the matched edition, NULL = not identified) and kind ('puzzle', 'date' or NULL). Needs
     * the CTEs of sqlCandidates() covering $series.
     */
    public static function sqlAnswer(string $series, string $puzzle, string $category, string $day): string
    {
        $distance = "CASE WHEN {$day} BETWEEN e.span_from AND e.span_to THEN 0"
            . " ELSE LEAST(ABS({$day} - e.span_from), ABS({$day} - e.span_to)) END";

        return <<<SQL
SELECT COALESCE(by_puzzle.competition_id, by_date.competition_id) AS competition_id,
    CASE
        WHEN by_puzzle.competition_id IS NOT NULL THEN 'puzzle'
        WHEN by_date.competition_id IS NOT NULL THEN 'date'
    END AS kind
FROM (
    SELECT CASE WHEN COUNT(DISTINCT d.competition_id) FILTER (WHERE d.distance IS NOT DISTINCT FROM d.best) = 1
            THEN CAST(MIN(CAST(d.competition_id AS TEXT)) FILTER (WHERE d.distance IS NOT DISTINCT FROM d.best) AS UUID)
        END AS competition_id,
        COUNT(*) AS candidates
    FROM (
        SELECT rp.competition_id,
            {$distance} AS distance,
            MIN({$distance}) OVER () AS best
        FROM series_match_round_puzzle rp
        INNER JOIN series_match_edition e ON e.competition_id = rp.competition_id
        WHERE rp.series_id = {$series}
            AND rp.puzzle_id = {$puzzle}
            AND rp.category = {$category}
    ) d
) by_puzzle
LEFT JOIN LATERAL (
    SELECT CASE WHEN COUNT(*) = 1 THEN CAST(MIN(CAST(e.competition_id AS TEXT)) AS UUID) END AS competition_id
    FROM series_match_edition e
    WHERE by_puzzle.candidates = 0
        AND e.series_id = {$series}
        AND {$day} BETWEEN e.span_from - 1 AND e.span_to + 1
        AND (e.categories IS NULL OR {$category} = ANY(e.categories))
) by_date ON true
SQL;
    }

    /**
     * Whether an automatic link still holds under its own kind (the reconciler's stickiness): a puzzle link while the
     * edition is a candidate with a revealed round of the category holding the puzzle, a date link while the edition is
     * a candidate whose widened span holds the day and accepts the category. False for no kind (series-level).
     */
    public static function sqlLinkHolds(string $competition, string $kind, string $series, string $puzzle, string $category, string $day): string
    {
        return <<<SQL
CASE {$kind}
    WHEN 'puzzle' THEN EXISTS (
        SELECT 1 FROM series_match_round_puzzle rp
        WHERE rp.competition_id = {$competition}
            AND rp.series_id = {$series}
            AND rp.puzzle_id = {$puzzle}
            AND rp.category = {$category}
    )
    WHEN 'date' THEN EXISTS (
        SELECT 1 FROM series_match_edition e
        WHERE e.competition_id = {$competition}
            AND e.series_id = {$series}
            AND {$day} BETWEEN e.span_from - 1 AND e.span_to + 1
            AND (e.categories IS NULL OR {$category} = ANY(e.categories))
    )
    ELSE false
END
SQL;
    }
}
