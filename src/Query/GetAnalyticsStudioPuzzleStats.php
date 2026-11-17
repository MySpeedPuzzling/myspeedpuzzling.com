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
 * - top(): the ten fastest solo solvers per puzzle by player id (cacheable); identities() says who they are now - a
 *   private record holder (PrivateProfileAccess::sqlIsPrivate(), no viewer = nobody unmasked) without name or id.
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

        $byKind = [];

        foreach ($rows as $row) {
            /** @var array{puzzle_id: string, kind: string, bests: string, first_tries: int|string, first_try_median: null|float|string, fastest_first_try: null|int|string} $row */
            $bests = trim($row['bests'], '{}');
            $byKind[strtolower($row['puzzle_id'])][$row['kind'] === 'group' ? 'group' : 'solo'] = [
                'bests' => $bests === '' ? [] : array_map('intval', explode(',', $bests)),
                'firstTries' => (int) $row['first_tries'],
                'firstTryMedian' => $row['first_try_median'] !== null ? (int) round((float) $row['first_try_median']) : null,
                'fastestFirstTry' => $row['fastest_first_try'] !== null ? (int) $row['fastest_first_try'] : null,
            ];
        }

        $empty = ['bests' => [], 'firstTries' => 0, 'firstTryMedian' => null, 'fastestFirstTry' => null];
        $result = [];

        foreach ($puzzleIds as $puzzleId) {
            $puzzleId = strtolower($puzzleId);
            $result[$puzzleId] = [
                'solo' => $byKind[$puzzleId]['solo'] ?? $empty,
                'group' => $category === RoundCategory::Solo ? null : ($byKind[$puzzleId]['group'] ?? $empty),
            ];
        }

        return $result;
    }

    /**
     * The ten fastest solo solvers per puzzle - by player id only, so a cached list never keeps a name: who they are
     * (and whether they are private now) is identities()' business, read on every answer.
     *
     * @param list<string> $puzzleIds
     * @return array<string, list<array{place: int, seconds: int, playerId: string}>>
     */
    public function top(array $puzzleIds, int $limit = 10): array
    {
        if ($puzzleIds === []) {
            return [];
        }

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
SELECT ranked.puzzle_id, ranked.place, ranked.seconds, ranked.player_id
FROM ranked
WHERE ranked.position <= :limit
ORDER BY ranked.puzzle_id, ranked.position
SQL,
            ['puzzleIds' => $puzzleIds, 'limit' => $limit],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            /** @var array{puzzle_id: string, place: int|string, seconds: int|string, player_id: string} $row */
            $result[strtolower($row['puzzle_id'])][] = [
                'place' => (int) $row['place'],
                'seconds' => (int) $row['seconds'],
                'playerId' => strtolower($row['player_id']),
            ];
        }

        return $result;
    }

    /**
     * Who the record holders are right now - one statement per answer, never cached: a private player
     * (PrivateProfileAccess::sqlIsPrivate(), no viewer = nobody unmasked) is null, so a player who turns private is
     * nameless on the very next answer.
     *
     * @param list<string> $playerIds
     * @return array<string, null|array{name: null|string, code: null|string, country: null|string}> null = private
     */
    public function identities(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $private = $this->privateProfileAccess->sqlIsPrivate('player');

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT player.id, player.name, player.code, player.country, {$private} AS is_private
FROM player
WHERE player.id IN (:playerIds)
SQL,
            ['playerIds' => array_values(array_unique($playerIds))],
            ['playerIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $identities = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: null|string, code: null|string, country: null|string, is_private: bool} $row */
            $identities[strtolower($row['id'])] = $row['is_private'] ? null : [
                'name' => $row['name'],
                'code' => $row['code'] !== null ? strtoupper($row['code']) : null,
                'country' => $row['country'],
            ];
        }

        return $identities;
    }
}
