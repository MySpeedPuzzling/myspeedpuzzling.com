<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * Community statistics of the analytics studio's visible puzzles (analytics-studio.md §2.5) - viewer-independent, two
 * statements for any number of puzzles (AnalyticsStudioPuzzleStatsCache keeps them):
 *
 * - distributions(): the best time of every solver per puzzle (sorted), first tries (count, median, fastest) - solo by
 *   player, and for a pair/team round also by `puzzling_team_id` of that category, which counts the imported results
 *   without a tracker (`player_id` NULL) as the group results they are;
 * - top(): the ten fastest solo solvers per puzzle with their public identity - a private record holder
 *   (PrivateProfileAccess::sqlIsPrivate(), no viewer = nobody unmasked) comes without name, country or id.
 *
 * Suspicious times are neither counted nor timed; an unfinished result never has a `seconds_to_solve`, so it is no
 * solve here; a relax solve (no time) is no time. The blocklist does not apply (no viewer - analytics-studio.md D3).
 */
readonly final class GetAnalyticsStudioPuzzleStats
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, array{solo: array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}, group: null|array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}}>
     */
    public function distributions(array $puzzleIds, RoundCategory $category): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $groupBranch = $category === RoundCategory::Solo ? '' : <<<SQL
    UNION ALL
    SELECT
        pst.puzzle_id,
        'group' AS kind,
        CAST(pst.puzzling_team_id AS text) AS solver,
        MIN(pst.seconds_to_solve) AS best,
        MIN(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt) AS first_try
    FROM puzzle_solving_time pst
    WHERE pst.puzzle_id IN (:puzzleIds)
        AND pst.puzzling_type = :groupType
        AND pst.puzzling_team_id IS NOT NULL
        AND pst.suspicious = false
        AND pst.seconds_to_solve > 0
    GROUP BY pst.puzzle_id, pst.puzzling_team_id
SQL;

        $rows = $this->database->executeQuery(
            <<<SQL
WITH best AS (
    SELECT
        pst.puzzle_id,
        'solo' AS kind,
        CAST(pst.player_id AS text) AS solver,
        MIN(pst.seconds_to_solve) AS best,
        MIN(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt) AS first_try
    FROM puzzle_solving_time pst
    WHERE pst.puzzle_id IN (:puzzleIds)
        AND pst.puzzling_type = 'solo'
        AND pst.player_id IS NOT NULL
        AND pst.suspicious = false
        AND pst.seconds_to_solve > 0
    GROUP BY pst.puzzle_id, pst.player_id
{$groupBranch}
)
SELECT
    puzzle_id,
    kind,
    array_agg(best ORDER BY best)::text AS bests,
    COUNT(first_try) AS first_tries,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY first_try) AS first_try_median,
    MIN(first_try) AS fastest_first_try
FROM best
GROUP BY puzzle_id, kind
SQL,
            ['puzzleIds' => $puzzleIds, 'groupType' => $category->value],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $empty = ['bests' => [], 'firstTries' => 0, 'firstTryMedian' => null, 'fastestFirstTry' => null];
        $result = [];

        foreach ($puzzleIds as $puzzleId) {
            $result[strtolower($puzzleId)] = ['solo' => $empty, 'group' => $category === RoundCategory::Solo ? null : $empty];
        }

        foreach ($rows as $row) {
            /** @var array{puzzle_id: string, kind: string, bests: string, first_tries: int|string, first_try_median: null|float|string, fastest_first_try: null|int|string} $row */
            $bests = trim($row['bests'], '{}');
            $entry = [
                'bests' => $bests === '' ? [] : array_map('intval', explode(',', $bests)),
                'firstTries' => (int) $row['first_tries'],
                'firstTryMedian' => $row['first_try_median'] !== null ? (int) round((float) $row['first_try_median']) : null,
                'fastestFirstTry' => $row['fastest_first_try'] !== null ? (int) $row['fastest_first_try'] : null,
            ];
            $result[strtolower($row['puzzle_id'])][$row['kind'] === 'group' ? 'group' : 'solo'] = $entry;
        }

        return $result;
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, list<array{place: int, seconds: int, playerId: null|string, name: null|string, code: null|string, country: null|string, private: bool}>>
     */
    public function top(array $puzzleIds, int $limit = 10): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $private = $this->privateProfileAccess->sqlIsPrivate('player');

        $rows = $this->database->executeQuery(
            <<<SQL
WITH best AS (
    SELECT pst.puzzle_id, pst.player_id, MIN(pst.seconds_to_solve) AS seconds
    FROM puzzle_solving_time pst
    WHERE pst.puzzle_id IN (:puzzleIds)
        AND pst.puzzling_type = 'solo'
        AND pst.player_id IS NOT NULL
        AND pst.suspicious = false
        AND pst.seconds_to_solve > 0
    GROUP BY pst.puzzle_id, pst.player_id
),
ranked AS (
    SELECT
        best.*,
        RANK() OVER (PARTITION BY best.puzzle_id ORDER BY best.seconds) AS place,
        ROW_NUMBER() OVER (PARTITION BY best.puzzle_id ORDER BY best.seconds, best.player_id) AS position
    FROM best
)
SELECT
    ranked.puzzle_id,
    ranked.place,
    ranked.seconds,
    player.id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    {$private} AS is_private
FROM ranked
INNER JOIN player ON player.id = ranked.player_id
WHERE ranked.position <= :limit
ORDER BY ranked.puzzle_id, ranked.position
SQL,
            ['puzzleIds' => $puzzleIds, 'limit' => $limit],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            /** @var array{puzzle_id: string, place: int|string, seconds: int|string, player_id: string, player_name: null|string, player_code: null|string, player_country: null|string, is_private: bool} $row */
            $public = $row['is_private'] === false;
            $result[strtolower($row['puzzle_id'])][] = [
                'place' => (int) $row['place'],
                'seconds' => (int) $row['seconds'],
                'playerId' => $public ? strtolower($row['player_id']) : null,
                'name' => $public ? $row['player_name'] : null,
                'code' => $public && $row['player_code'] !== null ? strtoupper($row['player_code']) : null,
                'country' => $public ? $row['player_country'] : null,
                'private' => $public === false,
            ];
        }

        return $result;
    }
}
