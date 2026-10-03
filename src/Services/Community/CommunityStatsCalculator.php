<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Community;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Rebuilds community_player_stats and community_scope_stats (docs/features/players-page/README.md).
 *
 * Native SQL on purpose: these are derived read models over every player and every result, rebuilt as one bulk
 * statement each - loading 11k players and half a million results as objects is exactly what the rule's bulk
 * exception is for. Player rows are only rewritten when a value changed, so a quiet quarter of an hour writes almost
 * nothing.
 */
readonly final class CommunityStatsCalculator
{
    public const int MONTHS = 12;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{players: int, scopes: int}
     */
    public function recalculate(DateTimeImmutable $now): array
    {
        $players = $this->recalculatePlayers($now);
        $scopes = $this->recalculateScopes($now);

        return ['players' => $players, 'scopes' => $scopes];
    }

    /**
     * The results of every player: their own tracked times and every pair/team time they are a registered member of,
     * each once. Unfinished competition results (pieces placed, no time) are not solves and stay out.
     */
    public static function resultsSql(string $nowParameter = ':now'): string
    {
        return <<<SQL
SELECT
    t.id AS time_id,
    t.player_id,
    t.puzzle_id,
    t.puzzling_type,
    CASE WHEN t.suspicious THEN NULL ELSE t.seconds_to_solve END AS seconds_to_solve,
    LEAST(COALESCE(t.finished_at, t.tracked_at), {$nowParameter}::timestamp) AS solved_at,
    t.tracked_at
FROM puzzle_solving_time t
WHERE t.seconds_to_solve IS NOT NULL OR t.pieces_placed IS NULL

UNION ALL

SELECT
    t.id,
    member.player_id,
    t.puzzle_id,
    t.puzzling_type,
    NULL,
    LEAST(COALESCE(t.finished_at, t.tracked_at), {$nowParameter}::timestamp),
    t.tracked_at
FROM puzzle_solving_time t
CROSS JOIN LATERAL (
    SELECT DISTINCT (elem ->> 'player_id')::uuid AS player_id
    FROM json_array_elements(t.team -> 'puzzlers') AS elem
    WHERE elem ->> 'player_id' IS NOT NULL AND elem ->> 'player_id' <> ''
) member
WHERE t.team IS NOT NULL
    AND member.player_id <> t.player_id
    AND (t.seconds_to_solve IS NOT NULL OR t.pieces_placed IS NULL)
SQL;
    }

    private function recalculatePlayers(DateTimeImmutable $now): int
    {
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $parameters = [
            'now' => self::format($now),
            'd7' => self::format($now->modify('-7 days')),
            'd30' => self::format($now->modify('-30 days')),
            'd60' => self::format($now->modify('-60 days')),
            'thisMonth' => self::format($monthStart),
            'lastMonth' => self::format($monthStart->modify('-1 month')),
        ];

        $monthly = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $from = $monthStart->modify(sprintf('-%d months', $i));
            $parameters['m' . $i] = self::format($from);
            $monthly[] = $i === 0
                ? 'COUNT(*) FILTER (WHERE r.solved_at >= :m0)'
                : sprintf('COUNT(*) FILTER (WHERE r.solved_at >= :m%d AND r.solved_at < :m%d)', $i, $i - 1);
        }
        $monthlySql = 'jsonb_build_array(' . implode(', ', $monthly) . ')';
        $emptyMonths = 'jsonb_build_array(' . implode(', ', array_fill(0, self::MONTHS, '0')) . ')';
        $results = self::resultsSql();

        $columns = [
            'solved_total', 'pieces_total', 'solves7d', 'pieces7d', 'solves30d', 'solves_prev30d',
            'solves_this_month', 'pieces_this_month', 'solves_last_month', 'pieces_last_month',
            'best500_seconds', 'best1000_seconds', 'first_solved_at', 'last_solved_at', 'monthly_solves', 'favorites_count',
        ];
        $updates = implode(', ', array_map(static fn (string $c): string => "{$c} = EXCLUDED.{$c}", $columns));
        $current = implode(', ', array_map(static fn (string $c): string => "community_player_stats.{$c}", $columns));
        $excluded = implode(', ', array_map(static fn (string $c): string => "EXCLUDED.{$c}", $columns));

        $sql = <<<SQL
INSERT INTO community_player_stats (
    player_id, solved_total, pieces_total, solves7d, pieces7d, solves30d, solves_prev30d,
    solves_this_month, pieces_this_month, solves_last_month, pieces_last_month,
    best500_seconds, best1000_seconds, first_solved_at, last_solved_at, monthly_solves, favorites_count, computed_at
)
WITH results AS ({$results}),
per_player AS (
    SELECT
        r.player_id,
        COUNT(*) AS solved_total,
        COALESCE(SUM(z.pieces_count), 0) AS pieces_total,
        COUNT(*) FILTER (WHERE r.solved_at >= :d7) AS solves7d,
        COALESCE(SUM(z.pieces_count) FILTER (WHERE r.solved_at >= :d7), 0) AS pieces7d,
        COUNT(*) FILTER (WHERE r.solved_at >= :d30) AS solves30d,
        COUNT(*) FILTER (WHERE r.solved_at >= :d60 AND r.solved_at < :d30) AS solves_prev30d,
        COUNT(*) FILTER (WHERE r.solved_at >= :thisMonth) AS solves_this_month,
        COALESCE(SUM(z.pieces_count) FILTER (WHERE r.solved_at >= :thisMonth), 0) AS pieces_this_month,
        COUNT(*) FILTER (WHERE r.solved_at >= :lastMonth AND r.solved_at < :thisMonth) AS solves_last_month,
        COALESCE(SUM(z.pieces_count) FILTER (WHERE r.solved_at >= :lastMonth AND r.solved_at < :thisMonth), 0) AS pieces_last_month,
        MIN(r.seconds_to_solve) FILTER (WHERE r.puzzling_type = 'solo' AND z.pieces_count = 500) AS best500_seconds,
        MIN(r.seconds_to_solve) FILTER (WHERE r.puzzling_type = 'solo' AND z.pieces_count = 1000) AS best1000_seconds,
        MIN(r.solved_at) AS first_solved_at,
        MAX(r.solved_at) AS last_solved_at,
        {$monthlySql} AS monthly_solves
    FROM results r
    JOIN puzzle z ON z.id = r.puzzle_id
    GROUP BY r.player_id
),
favorites AS (
    SELECT favorite.id::uuid AS player_id, COUNT(*) AS favorites_count
    FROM player follower
    CROSS JOIN LATERAL json_array_elements_text(follower.favorite_players) AS favorite(id)
    WHERE favorite.id ~ '^[0-9a-fA-F-]{36}$'
    GROUP BY 1
)
SELECT
    p.id,
    COALESCE(pp.solved_total, 0),
    COALESCE(pp.pieces_total, 0),
    COALESCE(pp.solves7d, 0),
    COALESCE(pp.pieces7d, 0),
    COALESCE(pp.solves30d, 0),
    COALESCE(pp.solves_prev30d, 0),
    COALESCE(pp.solves_this_month, 0),
    COALESCE(pp.pieces_this_month, 0),
    COALESCE(pp.solves_last_month, 0),
    COALESCE(pp.pieces_last_month, 0),
    pp.best500_seconds,
    pp.best1000_seconds,
    pp.first_solved_at,
    pp.last_solved_at,
    COALESCE(pp.monthly_solves, {$emptyMonths}),
    COALESCE(f.favorites_count, 0),
    :now
FROM player p
LEFT JOIN per_player pp ON pp.player_id = p.id
LEFT JOIN favorites f ON f.player_id = p.id
ON CONFLICT (player_id) DO UPDATE SET {$updates}, computed_at = EXCLUDED.computed_at
WHERE ({$current}) IS DISTINCT FROM ({$excluded})
SQL;

        return (int) $this->database->executeStatement($sql, $parameters);
    }

    private function recalculateScopes(DateTimeImmutable $now): int
    {
        $monthly = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $monthly[] = "COALESCE(SUM((s.monthly_solves ->> {$i})::int), 0)";
        }
        $monthlySql = 'jsonb_build_array(' . implode(', ', $monthly) . ')';

        // GROUPING SETS: one row per country plus the grand total, which is the world (players without a country
        // included). Players without a country also form a NULL group of their own - left out by the WHERE.
        $sql = <<<SQL
INSERT INTO community_scope_stats (
    scope, registered_players, active30d, solves30d, solves_prev30d, active_this_month, pieces_this_month,
    active_last_month, pieces_last_month, median_best500_seconds, puzzlers_with500, monthly_solves, new_faces14d,
    computed_at
)
SELECT * FROM (
    SELECT
        CASE WHEN GROUPING(p.country) = 1 THEN 'world' ELSE LOWER(p.country) END AS scope,
        COUNT(*) AS registered_players,
        COUNT(*) FILTER (WHERE s.solves30d > 0) AS active30d,
        COALESCE(SUM(s.solves30d), 0) AS solves30d,
        COALESCE(SUM(s.solves_prev30d), 0) AS solves_prev30d,
        COUNT(*) FILTER (WHERE s.solves_this_month > 0) AS active_this_month,
        COALESCE(SUM(s.pieces_this_month), 0) AS pieces_this_month,
        COUNT(*) FILTER (WHERE s.solves_last_month > 0) AS active_last_month,
        COALESCE(SUM(s.pieces_last_month), 0) AS pieces_last_month,
        ROUND(percentile_cont(0.5) WITHIN GROUP (ORDER BY s.best500_seconds))::int AS median_best500_seconds,
        COUNT(s.best500_seconds) AS puzzlers_with500,
        {$monthlySql} AS monthly_solves,
        COUNT(*) FILTER (WHERE p.registered_at >= :d14 AND p.is_private = false AND s.solved_total > 0) AS new_faces14d,
        :now::timestamp AS computed_at
    FROM player p
    JOIN community_player_stats s ON s.player_id = p.id
    GROUP BY GROUPING SETS ((p.country), ())
    HAVING GROUPING(p.country) = 1 OR (p.country IS NOT NULL AND p.country <> '' AND LENGTH(p.country) <= 10)
) scopes
ON CONFLICT (scope) DO UPDATE SET
    registered_players = EXCLUDED.registered_players,
    active30d = EXCLUDED.active30d,
    solves30d = EXCLUDED.solves30d,
    solves_prev30d = EXCLUDED.solves_prev30d,
    active_this_month = EXCLUDED.active_this_month,
    pieces_this_month = EXCLUDED.pieces_this_month,
    active_last_month = EXCLUDED.active_last_month,
    pieces_last_month = EXCLUDED.pieces_last_month,
    median_best500_seconds = EXCLUDED.median_best500_seconds,
    puzzlers_with500 = EXCLUDED.puzzlers_with500,
    monthly_solves = EXCLUDED.monthly_solves,
    new_faces14d = EXCLUDED.new_faces14d,
    computed_at = EXCLUDED.computed_at
SQL;

        $parameters = [
            'now' => self::format($now),
            'd14' => self::format($now->modify('-14 days')),
        ];

        $written = (int) $this->database->executeStatement($sql, $parameters);

        // A country nobody lives in any more (everyone moved or left) must not keep a stale row
        $this->database->executeStatement(
            'DELETE FROM community_scope_stats WHERE computed_at < :now',
            ['now' => self::format($now)],
        );

        return $written;
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
