<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\LeaderboardSlice;
use SpeedPuzzling\Web\Value\LeaderboardPosition;

/**
 * The window part of the Times boards' statements (GetFastestPlayers / GetFastestPairs / GetFastestGroups::window()),
 * so the three rank, cut and describe the viewer the same way (docs/features/xp-levels/ux-polish/design-spec.md §4).
 *
 * The statement first builds a CTE `BestTimes (entry_id, best_seconds)` - one row per player or pair/team of the board,
 * every filter applied. board() ranks it (RANK(): equal times share the place; ROW_NUMBER() by the id keeps the order
 * stable), finds the viewer's row and keeps the rows to show in
 * `shown (entry_id, best_seconds, rank, position, total, closest_faster)`. The statement joins its details onto `shown`
 * (carrying `rank`, `position`, `total` and `closest_faster`) and ends with selectWithOwn(), whose rows slice() turns
 * into a LeaderboardSlice. Parameters: :limit, :above, :below, :milestones (milestones()) and whatever the viewer condition uses.
 */
final class TimesLeaderboardSql
{
    /**
     * @param string $viewerCondition SQL on `board` - the rows that are the viewer's (their player id, or every pair/team
     *                                they are in: the best of them is theirs, like on the puzzle page)
     */
    public static function board(string $viewerCondition): string
    {
        return <<<SQL
board AS MATERIALIZED (
    SELECT
        BestTimes.entry_id,
        BestTimes.best_seconds,
        RANK() OVER (ORDER BY BestTimes.best_seconds) AS rank,
        ROW_NUMBER() OVER (ORDER BY BestTimes.best_seconds, BestTimes.entry_id) AS position,
        COUNT(*) OVER () AS total,
        -- The closest faster time - right above a row even when that row is not shown (the viewer's rows after a gap)
        MAX(BestTimes.best_seconds) OVER (ORDER BY BestTimes.best_seconds RANGE BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) AS closest_faster
    FROM BestTimes
),
-- MATERIALIZED: computed once whatever the number of references - an inlined CTE joined ON TRUE re-scans the whole
-- board per row (the trap that made the Level board quadratic, 2026-10-11)
own AS MATERIALIZED (
    SELECT
        board.position AS own_position,
        board.rank AS own_rank,
        board.best_seconds AS own_value,
        board.total AS board_total,
        (SELECT COUNT(*) FROM board behind WHERE behind.best_seconds > board.best_seconds) AS rows_behind,
        (SELECT json_object_agg(m.position, m.best_seconds) FROM board m WHERE m.position = ANY(CAST(:milestones AS int[]))) AS milestone_values
    FROM board
    WHERE {$viewerCondition}
    ORDER BY board.position
    LIMIT 1
),
shown AS (
    SELECT board.*
    FROM board
    LEFT JOIN own ON TRUE
    WHERE board.position <= :limit
        OR board.position BETWEEN own.own_position - :above AND own.own_position + :below
)
SQL;
    }

    /**
     * The final SELECT: every row of $detailCte (which carries `rank`, `position` and `total` from `shown`) with the
     * viewer's columns - and one row of the viewer's columns alone when there is no row to show
     */
    public static function selectWithOwn(string $detailCte): string
    {
        return <<<SQL
SELECT {$detailCte}.*, own.own_position, own.own_rank, own.own_value, own.board_total, own.rows_behind, own.milestone_values
FROM (SELECT 1) AS anchor
LEFT JOIN own ON TRUE
LEFT JOIN {$detailCte} ON TRUE
ORDER BY {$detailCte}.position
SQL;
    }

    public static function milestones(): string
    {
        return '{' . implode(',', LeaderboardPosition::RANK_MILESTONES) . '}';
    }

    /**
     * @template T of object
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): T $hydrate
     * @return LeaderboardSlice<T>
     */
    public static function slice(array $rows, callable $hydrate): LeaderboardSlice
    {
        $items = [];
        $ranks = [];
        $positions = [];
        $closestFaster = [];
        $total = 0;

        foreach ($rows as $row) {
            if (!is_numeric($row['position'] ?? null)) {
                continue;
            }

            $items[] = $hydrate($row);
            $ranks[] = self::int($row['rank']);
            $positions[] = self::int($row['position']) - 1;
            $closestFaster[] = is_numeric($row['closest_faster'] ?? null) ? (int) $row['closest_faster'] : null;
            $total = self::int($row['total']);
        }

        $first = $rows[0] ?? null;

        if ($first === null || !is_numeric($first['own_rank'] ?? null)) {
            return new LeaderboardSlice($items, $ranks, $positions, $total, null, null, $closestFaster);
        }

        $milestoneValues = [];
        $decoded = is_string($first['milestone_values']) ? json_decode($first['milestone_values'], true) : null;

        foreach (is_array($decoded) ? $decoded : [] as $position => $value) {
            $milestoneValues[(int) $position] = is_numeric($value) ? (int) $value : null;
        }

        $viewerPosition = LeaderboardPosition::describe(
            rank: self::int($first['own_rank']),
            total: self::int($first['board_total']),
            ownValue: self::int($first['own_value']),
            milestoneValues: $milestoneValues,
            higherIsBetter: false,
            rowsBehind: self::int($first['rows_behind']),
        );

        return new LeaderboardSlice($items, $ranks, $positions, $total, self::int($first['own_position']) - 1, $viewerPosition, $closestFaster);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
