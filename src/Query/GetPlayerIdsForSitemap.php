<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Public (non-private) player profiles with a name for route player_profile - only those with at
 * least one result on them, solo or as a member of a pair / team (what the profile lists). A
 * profile without a single time is an empty page.
 *
 * Group membership comes from puzzling_team_member, not the `team` JSON snapshot - a correlated
 * JSON containment check re-scanned every group time per player and ran for minutes on production
 * (Sentry WEB-CW).
 */
readonly final class GetPlayerIdsForSitemap
{
    private const string PUBLIC_WITH_RESULTS = <<<SQL
player.is_private = false
    AND player.name IS NOT NULL
    AND player.name != ''
    AND (
        EXISTS (
            SELECT 1
            FROM puzzle_solving_time
            WHERE puzzle_solving_time.player_id = player.id
        )
        OR EXISTS (
            SELECT 1
            FROM puzzling_team_member
            INNER JOIN puzzle_solving_time ON puzzle_solving_time.puzzling_team_id = puzzling_team_member.team_id
            WHERE puzzling_team_member.player_id = player.id
        )
    )
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * ~110 ms on the production copy.
     */
    public function countPublicWithResults(): int
    {
        $condition = self::PUBLIC_WITH_RESULTS;

        $query = <<<SQL
SELECT COUNT(player.id)
FROM player
WHERE {$condition}
SQL;

        $count = $this->database
            ->executeQuery($query)
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * `lastmod` is the day the player's latest result (own or in a pair / team) was logged - the
     * profile lists every result. The page of players is cut first, then the latest result of its
     * players is aggregated once for own times and once for pair / team times: 20-160 ms per sitemap
     * file (1 666 players) on the production copy, the first file the slowest.
     *
     * Not as two correlated MAX() subqueries per player: same work, but the planner estimated that
     * shape at ~460k for the first file, just under jit_inline_above_cost (500k) - production
     * JIT-compiled it on every fetch, and past 500k inlining + optimisation would have added ~170 ms.
     * This shape is estimated at ~40k (docs/features/seo/performance-2026-10.md).
     *
     * @return list<array{id: string, lastmod: null|string}>
     */
    public function publicWithResultsPage(int $limit, int $offset): array
    {
        $condition = self::PUBLIC_WITH_RESULTS;

        $query = <<<SQL
WITH page AS (
    SELECT player.id
    FROM player
    WHERE {$condition}
    ORDER BY player.id
    LIMIT :limit OFFSET :offset
),
own_results AS (
    SELECT puzzle_solving_time.player_id, MAX(puzzle_solving_time.tracked_at) AS last_tracked_at
    FROM puzzle_solving_time
    WHERE puzzle_solving_time.player_id IN (SELECT page.id FROM page)
    GROUP BY puzzle_solving_time.player_id
),
group_results AS (
    SELECT puzzling_team_member.player_id, MAX(puzzle_solving_time.tracked_at) AS last_tracked_at
    FROM puzzling_team_member
    INNER JOIN puzzle_solving_time ON puzzle_solving_time.puzzling_team_id = puzzling_team_member.team_id
    WHERE puzzling_team_member.player_id IN (SELECT page.id FROM page)
    GROUP BY puzzling_team_member.player_id
)
SELECT
    page.id,
    to_char(GREATEST(own_results.last_tracked_at, group_results.last_tracked_at), 'YYYY-MM-DD') AS lastmod
FROM page
LEFT JOIN own_results ON own_results.player_id = page.id
LEFT JOIN group_results ON group_results.player_id = page.id
ORDER BY page.id
SQL;

        /** @var list<array{id: string, lastmod: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return $rows;
    }
}
