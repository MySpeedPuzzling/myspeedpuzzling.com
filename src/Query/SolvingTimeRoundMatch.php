<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * THE rule that finds the round of a solving time (docs/features/competitions-management/shared-puzzles-and-divisions.md
 * §2.3 + "Review amendments" A1-A4) - the only place it is written. SolvingTimeRoundResolver runs it for one time
 * (before flush, from the entity), RoundResultsReconciler over every time in scope. Nothing else may restate it.
 *
 * Candidates C: the rounds of the time's competition whose category is the time's (solo/duo/team) and that hold its
 * puzzle.
 * - |C| = 0 -> no round. |C| = 1 -> that round (every competition that never shares a puzzle between two rounds of one
 *   category - no evidence is even computed, A1-A3 apply only to several candidates).
 * - A round the player named (`round_chosen`) is kept while it is a candidate. A named round that is no longer one: the
 *   flag goes and the time stays unlinked (A4 - never moved onto another round of a time the player placed).
 * - Exclusions (A1): a round is dropped when the time was saved (tracked_at) before the round began (its start time
 *   known), when the time's day (finished_at, known to the day) is before the round's local day (RoundTimezone::
 *   sqlResolve() - the zone PHP reads the round in), or when a person of the time has a "did not start" entry in it.
 * - Entries (A3): the persons of the time are its owner and the registered people of its group (`team` JSON - the
 *   resolver hands over the same JSON built from the entity). An entry = a competition_participant_round of a
 *   non-deleted participant connected to the person, not "did not start" (a pair/team entry's result is its
 *   competition_team's). A round qualifies when every person with an entry in any remaining round has one in it.
 *   Nobody with an entry: exactly one remaining round -> it, else no round. One qualifying round -> it. Several -> the
 *   one whose entry recorded the time's result (seconds, or pieces placed of an unfinished one), if exactly one does.
 * - Anything else -> no round: the evidence never guesses (the time stays linked to its event, round unknown).
 *
 * Usage: `WITH ` . sqlCandidates($timesSelect) . ` SELECT … FROM (` . sqlAnswer() . `) answer`. $timesSelect is a
 * SELECT of one row per time with exactly these columns: time_id, competition_id, puzzle_id, category (varchar),
 * current_round_id, chosen (bool), owner_id, team_json (json), seconds, pieces_placed, solve_day (date, NULL when the day
 * is not known exactly), tracked_at (timestamp, UTC). The answer has one row per time with at least one candidate:
 * time_id, round_id (NULL = none), chosen, candidates.
 */
readonly final class SolvingTimeRoundMatch
{
    // Bits of the person mask - more registered people on one time than this share the last bit
    private const int MAX_PERSON_BIT = 62;

    public static function sqlCandidates(string $timesSelect): string
    {
        $zone = RoundTimezone::sqlResolve('c.round_timezone', 'rc.location_country_code', 'rcs.location_country_code');
        $maxBit = self::MAX_PERSON_BIT;

        return <<<SQL
round_match_time AS (
{$timesSelect}
),
round_match_counted AS (
    SELECT t.*,
        cr.id AS round_id,
        cr.competition_id AS round_competition_id,
        cr.starts_at AS round_starts_at,
        cr.start_time_known AS round_start_time_known,
        cr.timezone AS round_timezone,
        COUNT(*) OVER (PARTITION BY t.time_id) AS n
    FROM round_match_time t
    INNER JOIN competition_round cr ON cr.competition_id = t.competition_id AND cr.category = t.category
    WHERE EXISTS (SELECT 1 FROM competition_round_puzzle crp WHERE crp.round_id = cr.id AND crp.puzzle_id = t.puzzle_id)
),
round_match_candidate AS (
    SELECT c.time_id,
        c.current_round_id,
        c.chosen,
        c.round_id,
        c.n,
        COALESCE(ev.excluded, false) AS excluded,
        COALESCE(ev.entry_mask, 0) AS entry_mask,
        COALESCE(ev.result_match, false) AS result_match
    FROM round_match_counted c
    LEFT JOIN LATERAL (
        SELECT entries.has_dns
                OR (c.round_start_time_known AND c.tracked_at < c.round_starts_at)
                OR (c.solve_day IS NOT NULL AND c.solve_day < CAST((c.round_starts_at AT TIME ZONE 'UTC') AT TIME ZONE zone.name AS DATE)) AS excluded,
            entries.entry_mask,
            entries.result_match
        FROM (
            SELECT {$zone} AS name
            FROM competition rc
            LEFT JOIN competition_series rcs ON rcs.id = rc.series_id
            WHERE rc.id = c.round_competition_id
        ) zone
        CROSS JOIN (
            SELECT COALESCE(bit_or(CASE WHEN entry.dns = false THEN CAST(1 AS BIGINT) << person.bit END), 0) AS entry_mask,
                COALESCE(bool_or(entry.dns), false) AS has_dns,
                COALESCE(bool_or(entry.dns = false AND (entry.seconds = c.seconds OR (c.pieces_placed IS NOT NULL AND entry.pieces_placed = c.pieces_placed))), false) AS result_match
            FROM (
                SELECT c.owner_id AS player_id, 0 AS bit
                WHERE c.owner_id IS NOT NULL
                UNION ALL
                SELECT CAST(member.value ->> 'player_id' AS UUID), CAST(LEAST(member.position, {$maxBit}) AS INT)
                FROM json_array_elements(COALESCE(c.team_json -> 'puzzlers', CAST('[]' AS JSON))) WITH ORDINALITY AS member(value, position)
                WHERE member.value ->> 'player_id' ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
            ) person
            INNER JOIN competition_participant cp ON cp.player_id = person.player_id AND cp.deleted_at IS NULL
            INNER JOIN competition_participant_round cpr ON cpr.participant_id = cp.id AND cpr.round_id = c.round_id
            LEFT JOIN competition_team ct ON ct.id = cpr.team_id
            CROSS JOIN LATERAL (
                SELECT CASE WHEN cpr.team_id IS NULL THEN cpr.result_did_not_start ELSE ct.result_did_not_start END AS dns,
                    CASE WHEN cpr.team_id IS NULL THEN cpr.result_seconds ELSE ct.result_seconds END AS seconds,
                    CASE WHEN cpr.team_id IS NULL THEN cpr.result_pieces_placed ELSE ct.result_pieces_placed END AS pieces_placed
            ) entry
        ) entries
        WHERE c.n > 1
    ) ev ON true
),
round_match_evaluated AS (
    SELECT candidate.*,
        COALESCE(bit_or(candidate.entry_mask) FILTER (WHERE candidate.excluded = false) OVER (PARTITION BY candidate.time_id), 0) AS union_mask
    FROM round_match_candidate candidate
)
SQL;
    }

    public static function sqlAnswer(): string
    {
        $qualifies = 'e.excluded = false AND e.union_mask <> 0 AND e.entry_mask = e.union_mask';

        return <<<SQL
SELECT e.time_id,
    CASE
        WHEN MAX(e.n) = 1 THEN (array_agg(e.round_id))[1]
        WHEN bool_or(e.chosen AND e.round_id = e.current_round_id) THEN (array_agg(e.round_id) FILTER (WHERE e.round_id = e.current_round_id))[1]
        WHEN bool_or(e.chosen) THEN NULL
        WHEN MAX(e.union_mask) = 0 THEN CASE
            WHEN COUNT(*) FILTER (WHERE e.excluded = false) = 1 THEN (array_agg(e.round_id) FILTER (WHERE e.excluded = false))[1]
        END
        WHEN COUNT(*) FILTER (WHERE {$qualifies}) = 1 THEN (array_agg(e.round_id) FILTER (WHERE {$qualifies}))[1]
        WHEN COUNT(*) FILTER (WHERE {$qualifies} AND e.result_match) = 1 THEN (array_agg(e.round_id) FILTER (WHERE {$qualifies} AND e.result_match))[1]
    END AS round_id,
    COALESCE(bool_or(e.chosen AND e.round_id = e.current_round_id), false) AS chosen,
    MAX(e.n) AS candidates
FROM round_match_evaluated e
GROUP BY e.time_id
SQL;
    }
}
