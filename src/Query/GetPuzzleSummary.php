<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleSummary;
use SpeedPuzzling\Web\Results\PuzzleUsedAtLine;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * The public facts of a puzzle page's "About this puzzle" section, meta description and (signed in) Details, in one
 * query: the precomputed puzzle_statistics row (a primary key lookup) plus where the puzzle was used.
 *
 * "Used at" (docs/features/events-page/high-frequency-series.md P24) is lines: first the rounds of publicly visible
 * competitions holding the puzzle, newest first, at most PuzzleSummary::USED_AT_ROUNDS_LIMIT (the rest counted), then
 * the competitions (or whole series) whose tag the puzzle carries and that no round line names - a series tag counts
 * as named when a round line is of one of its editions. Secret puzzles: a round that still keeps the puzzle hidden
 * (RoundPuzzleReveal, until GetEditionRounds reveals it too) is left out, as is a puzzle under a platform-wide embargo
 * (hide_until) - in rounds and tags alike; listed by a tag, the puzzle still obeys every round of that event. Otherwise
 * this list would leak what the event page keeps secret. Drafts, pending and rejected events never appear.
 */
readonly final class GetPuzzleSummary
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function forPuzzle(string $puzzleId): PuzzleSummary
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $visibleCompetition = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $visibleSeries = IsSeriesPubliclyVisible::SQL_CONDITION;
        $roundPuzzleHidden = RoundPuzzleReveal::sqlHidden('crp', 'cr');
        $roundsLimit = PuzzleSummary::USED_AT_ROUNDS_LIMIT;

        $query = <<<SQL
WITH used_round AS (
    SELECT
        cr.id AS round_id,
        CAST(cr.category AS VARCHAR) AS category,
        to_char(cr.starts_at, 'YYYY-MM-DD HH24:MI:SS') AS starts_at,
        cr.timezone,
        c.id AS competition_id,
        c.name,
        c.slug,
        c.location_country_code AS country_code,
        c.series_id,
        cs.name AS series_name,
        cs.slug AS series_slug,
        cs.location_country_code AS series_country_code
    FROM competition_round_puzzle crp
    INNER JOIN puzzle rp ON rp.id = crp.puzzle_id
    INNER JOIN competition_round cr ON cr.id = crp.round_id
    INNER JOIN competition c ON c.id = cr.competition_id
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    WHERE crp.puzzle_id = :puzzleId
        AND (rp.hide_until IS NULL OR rp.hide_until <= :now::timestamp)
        AND NOT {$roundPuzzleHidden}
        AND {$visibleCompetition}
),
used_tag AS (
    SELECT
        c.name,
        c.slug,
        cs.name AS series_name,
        cs.slug AS series_slug,
        false AS is_series,
        c.date_from
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    INNER JOIN puzzle tp_puzzle ON tp_puzzle.id = :puzzleId
    WHERE {$visibleCompetition}
        AND c.tag_id IN (SELECT tp.tag_id FROM tag_puzzle tp WHERE tp.puzzle_id = :puzzleId)
        AND c.id NOT IN (SELECT used_round.competition_id FROM used_round)
        -- Listed by the event's tag, the puzzle still obeys the reveal: no secret puzzle, no round of this event still
        -- keeping it secret
        AND (tp_puzzle.hide_until IS NULL OR tp_puzzle.hide_until <= :now::timestamp)
        AND NOT EXISTS (
            SELECT 1
            FROM competition_round_puzzle crp
            INNER JOIN competition_round cr ON cr.id = crp.round_id
            WHERE crp.puzzle_id = :puzzleId
                AND cr.competition_id = c.id
                AND {$roundPuzzleHidden}
        )
    UNION ALL
    SELECT
        cs.name,
        cs.slug,
        NULL,
        NULL,
        true,
        NULL
    FROM competition_series cs
    WHERE cs.tag_id IN (SELECT tp.tag_id FROM tag_puzzle tp WHERE tp.puzzle_id = :puzzleId)
        AND {$visibleSeries}
        AND cs.id NOT IN (SELECT used_round.series_id FROM used_round WHERE used_round.series_id IS NOT NULL)
)
SELECT
    COALESCE(ps.solved_times_solo_count, 0) AS solo_count,
    ps.median_time_solo,
    ps.fastest_time_solo,
    COALESCE(ps.solved_times_duo_count, 0) AS duo_count,
    ps.median_time_duo,
    ps.fastest_time_duo,
    COALESCE(ps.solved_times_team_count, 0) AS team_count,
    ps.median_time_team,
    ps.fastest_time_team,
    (
        SELECT COALESCE(json_agg(newest ORDER BY newest.starts_at DESC, newest.round_id), '[]'::json)
        FROM (
            SELECT round_id, category, starts_at, timezone, name, slug, country_code, series_name, series_slug, series_country_code
            FROM used_round
            ORDER BY starts_at DESC, round_id
            LIMIT {$roundsLimit}
        ) newest
    ) AS used_at_rounds,
    (SELECT COUNT(*) FROM used_round) AS used_at_rounds_count,
    (
        SELECT COALESCE(json_agg(used_tag ORDER BY used_tag.is_series, used_tag.date_from NULLS LAST, used_tag.name), '[]'::json)
        FROM used_tag
    ) AS used_at_tags
FROM puzzle p
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
WHERE p.id = :puzzleId
SQL;

        $now = $this->clock->now();

        /**
         * @var false|array{
         *     solo_count: int|string,
         *     median_time_solo: null|int|string,
         *     fastest_time_solo: null|int|string,
         *     duo_count: int|string,
         *     median_time_duo: null|int|string,
         *     fastest_time_duo: null|int|string,
         *     team_count: int|string,
         *     median_time_team: null|int|string,
         *     fastest_time_team: null|int|string,
         *     used_at_rounds: string,
         *     used_at_rounds_count: int|string,
         *     used_at_tags: string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
                'now' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new PuzzleNotFound();
        }

        /**
         * @var list<array{
         *     round_id: string,
         *     category: string,
         *     starts_at: string,
         *     timezone: null|string,
         *     name: string,
         *     slug: null|string,
         *     country_code: null|string,
         *     series_name: null|string,
         *     series_slug: null|string,
         *     series_country_code: null|string,
         * }> $rounds
         */
        $rounds = json_decode($row['used_at_rounds'], true, flags: JSON_THROW_ON_ERROR);

        /**
         * @var list<array{
         *     name: string,
         *     slug: null|string,
         *     series_name: null|string,
         *     series_slug: null|string,
         *     is_series: bool,
         * }> $tags
         */
        $tags = json_decode($row['used_at_tags'], true, flags: JSON_THROW_ON_ERROR);

        return new PuzzleSummary(
            soloSolvesCount: (int) $row['solo_count'],
            medianTimeSolo: self::nullableInt($row['median_time_solo']),
            fastestTimeSolo: self::nullableInt($row['fastest_time_solo']),
            duoSolvesCount: (int) $row['duo_count'],
            medianTimeDuo: self::nullableInt($row['median_time_duo']),
            fastestTimeDuo: self::nullableInt($row['fastest_time_duo']),
            teamSolvesCount: (int) $row['team_count'],
            medianTimeTeam: self::nullableInt($row['median_time_team']),
            fastestTimeTeam: self::nullableInt($row['fastest_time_team']),
            usedAt: [
                ...array_map(PuzzleUsedAtLine::ofRound(...), $rounds),
                ...array_map(PuzzleUsedAtLine::ofTag(...), $tags),
            ],
            usedAtMore: max(0, (int) $row['used_at_rounds_count'] - count($rounds)),
        );
    }

    private static function nullableInt(null|int|string $value): null|int
    {
        return $value === null ? null : (int) $value;
    }
}
