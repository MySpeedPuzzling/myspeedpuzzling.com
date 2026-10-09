<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The rounds an occurrence is dated by (OccurrenceDates::sessions()), shared by every statement that dates
 * occurrences - the events page (GetEventOccurrences), the archive years of the sitemap and "You organize". One LEFT
 * JOIN aliased `r` on `c` (competition): `r.rounds` (a JSON list in start order, NULL without rounds) and
 * `r.round_count`.
 *
 * SQL_JOIN_WITH_RESULTS (GetEventOccurrences only - the events page and the series page) adds each round's
 * `has_results`: a time logged in it (not suspicious) or published official results - so a session of several gets its
 * own Results tag (docs/features/events-page/detail-pages.md, conflict 5) - its `category` and `puzzles`: the names of
 * its **revealed** round puzzles (docs/features/events-page/high-frequency-series.md "Events search by puzzle" - never
 * one the round still keeps secret, RoundPuzzleReveal::sqlHidden(), nor a puzzle hidden on the whole site,
 * puzzle.hide_until), read at `:now`. The sitemap's years and "You organize" do not need them and keep the lighter join.
 */
final class OccurrenceRounds
{
    public const string SQL_JOIN = <<<SQL
LEFT JOIN (
    SELECT competition_id,
        json_agg(json_build_object('id', id, 'name', name, 'starts_at', starts_at, 'timezone', timezone) ORDER BY starts_at, id) AS rounds,
        COUNT(*) AS round_count
    FROM competition_round
    GROUP BY competition_id
) r ON r.competition_id = c.id
SQL;

    public const string SQL_JOIN_WITH_RESULTS = <<<SQL
LEFT JOIN (
    SELECT cr_j.competition_id,
        json_agg(json_build_object(
            'id', cr_j.id,
            'name', cr_j.name,
            'starts_at', cr_j.starts_at,
            'timezone', cr_j.timezone,
            'has_results', (
                EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.competition_round_id = cr_j.id AND pst.suspicious = false)
                OR %s
            ),
            'category', cr_j.category,
            'puzzles', (
                SELECT json_agg(p_j.name ORDER BY crp_j.id)
                FROM competition_round_puzzle crp_j
                INNER JOIN puzzle p_j ON p_j.id = crp_j.puzzle_id
                WHERE crp_j.round_id = cr_j.id
                    AND NOT %s
                    AND (p_j.hide_until IS NULL OR p_j.hide_until <= CAST(:now AS TIMESTAMP))
            )
        ) ORDER BY cr_j.starts_at, cr_j.id) AS rounds,
        COUNT(*) AS round_count
    FROM competition_round cr_j
    %s
    GROUP BY cr_j.competition_id
) r ON r.competition_id = c.id
SQL;

    /**
     * Needs the parameter `:now` (Y-m-d H:i:s, UTC) - the moment the round puzzles' reveal is read at.
     *
     * @param string $roundsWhere a WHERE on `cr_j` (competition_round) inside the aggregate - the series page passes
     *     its own competitions so it does not aggregate every round on the site; empty = all rounds (the events page)
     */
    public static function sqlJoinWithResults(string $roundsWhere = ''): string
    {
        return sprintf(
            self::SQL_JOIN_WITH_RESULTS,
            GetPublishedRoundResults::sqlShowsOfficialResults('cr_j'),
            RoundPuzzleReveal::sqlHidden('crp_j', 'cr_j', ':now'),
            $roundsWhere,
        );
    }

    /**
     * @param null|string ...$countryCodes the event's own, then its series' country - RoundTimezone::resolve()
     *
     * @return list<OccurrenceRound>
     */
    public static function fromJson(mixed $json, null|string ...$countryCodes): array
    {
        if (is_string($json) === false || $json === '') {
            return [];
        }

        $items = json_decode($json, true);

        if (is_array($items) === false) {
            return [];
        }

        $rounds = [];

        foreach ($items as $item) {
            if (is_array($item) === false || is_string($item['starts_at'] ?? null) === false) {
                continue;
            }

            $zone = is_string($item['timezone'] ?? null) ? $item['timezone'] : null;
            $puzzles = is_array($item['puzzles'] ?? null) ? $item['puzzles'] : [];

            $rounds[] = new OccurrenceRound(
                id: is_string($item['id'] ?? null) ? $item['id'] : '',
                name: is_string($item['name'] ?? null) ? $item['name'] : '',
                startsAt: new DateTimeImmutable($item['starts_at'], new DateTimeZone('UTC')),
                zone: RoundTimezone::resolve($zone, ...$countryCodes),
                zoneAssumed: RoundTimezone::isAssumed($zone, ...$countryCodes),
                hasResults: ($item['has_results'] ?? false) === true,
                category: is_string($item['category'] ?? null) ? $item['category'] : null,
                puzzleNames: array_values(array_filter($puzzles, static fn (mixed $name): bool => is_string($name) && trim($name) !== '')),
            );
        }

        return $rounds;
    }
}
