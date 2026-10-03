<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Community;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\PlayerMomentType;

/**
 * Detects player moments (personal bests, milestones, first results) among the results solved in the last
 * WINDOW_DAYS days and syncs player_moment with them - docs/features/players-page/README.md.
 *
 * A moment's identity is (player, dedupe key), so re-runs update a row in place and its id stays stable. Inside the
 * window the table mirrors what holds now: a moment whose result was edited away is removed. Older moments are never
 * touched. Native SQL for the same reason as CommunityStatsCalculator: a bulk derived read model.
 */
readonly final class PlayerMomentDetector
{
    public const int WINDOW_DAYS = 14;

    private const int INSERT_CHUNK = 500;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{detected: int, written: int, removed: int}
     */
    public function detect(DateTimeImmutable $now): array
    {
        $since = $now->modify(sprintf('-%d days', self::WINDOW_DAYS));
        $parameters = [
            'now' => $now->format('Y-m-d H:i:s'),
            'since' => $since->format('Y-m-d H:i:s'),
        ];

        $moments = [...$this->personalBests($parameters), ...$this->countMoments($parameters)];
        $written = $this->upsert($moments, $now);

        $keys = array_map(static fn (array $moment): string => $moment['player_id'] . '|' . $moment['dedupe_key'], $moments);

        $removed = $keys === []
            ? (int) $this->database->executeStatement(
                'DELETE FROM player_moment WHERE occurred_at >= :since',
                ['since' => $parameters['since']],
            )
            : (int) $this->database->executeStatement(
                "DELETE FROM player_moment WHERE occurred_at >= :since AND (player_id::text || '|' || dedupe_key) NOT IN (:keys)",
                ['since' => $parameters['since'], 'keys' => $keys],
                ['keys' => ArrayParameterType::STRING],
            );

        return ['detected' => count($moments), 'written' => $written, 'removed' => $removed];
    }

    /**
     * Solo times solved inside the window that beat every earlier solo time of the same player on the same piece
     * count. "Earlier" orders by solved at, then tracked at - a back-dated entry is compared with what came before it.
     *
     * @param array{now: string, since: string} $parameters
     * @return list<array{player_id: string, type: string, dedupe_key: string, occurred_at: string, solving_time_id: null|string, pieces_count: null|int, value: null|int, previous_value: null|int}>
     */
    private function personalBests(array $parameters): array
    {
        $sql = <<<SQL
WITH candidates AS (
    SELECT DISTINCT player_id
    FROM puzzle_solving_time
    WHERE puzzling_type = 'solo' AND tracked_at >= :since
),
solo AS (
    SELECT
        t.id,
        t.player_id,
        z.pieces_count,
        t.seconds_to_solve,
        LEAST(COALESCE(t.finished_at, t.tracked_at), :now::timestamp) AS solved_at,
        t.tracked_at
    FROM puzzle_solving_time t
    JOIN candidates c ON c.player_id = t.player_id
    JOIN puzzle z ON z.id = t.puzzle_id
    WHERE t.puzzling_type = 'solo' AND t.seconds_to_solve IS NOT NULL AND t.suspicious = false
),
ranked AS (
    SELECT
        solo.*,
        MIN(solo.seconds_to_solve) OVER (
            PARTITION BY solo.player_id, solo.pieces_count
            ORDER BY solo.solved_at, solo.tracked_at, solo.id
            ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
        ) AS previous_best
    FROM solo
)
SELECT
    player_id,
    id AS solving_time_id,
    pieces_count,
    seconds_to_solve AS value,
    previous_best AS previous_value,
    solved_at AS occurred_at
FROM ranked
WHERE solved_at >= :since AND previous_best IS NOT NULL AND seconds_to_solve < previous_best
SQL;

        /** @var list<array{player_id: string, solving_time_id: string, pieces_count: int|string, value: int|string, previous_value: int|string, occurred_at: string}> $rows */
        $rows = $this->database->executeQuery($sql, $parameters)->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'player_id' => $row['player_id'],
            'type' => PlayerMomentType::PersonalBest->value,
            'dedupe_key' => 'pb:' . $row['solving_time_id'],
            'occurred_at' => $row['occurred_at'],
            'solving_time_id' => $row['solving_time_id'],
            'pieces_count' => (int) $row['pieces_count'],
            'value' => (int) $row['value'],
            'previous_value' => (int) $row['previous_value'],
        ], $rows);
    }

    /**
     * Moments of a player's running totals - the n-th result, the pieces crossing a milestone, the very first result -
     * for every player with a result tracked inside the window. Results include pair/team times of registered members.
     *
     * @param array{now: string, since: string} $parameters
     * @return list<array{player_id: string, type: string, dedupe_key: string, occurred_at: string, solving_time_id: null|string, pieces_count: null|int, value: null|int, previous_value: null|int}>
     */
    private function countMoments(array $parameters): array
    {
        $results = CommunityStatsCalculator::resultsSql();
        $puzzleMilestones = 'ARRAY[' . implode(',', PlayerMomentType::PUZZLE_MILESTONES) . ']';
        $piecesMilestones = 'ARRAY[' . implode(',', PlayerMomentType::PIECES_MILESTONES) . ']';

        $sql = <<<SQL
WITH candidates AS (
    SELECT t.player_id
    FROM puzzle_solving_time t
    WHERE t.tracked_at >= :since
    UNION
    SELECT (elem ->> 'player_id')::uuid
    FROM puzzle_solving_time t
    CROSS JOIN LATERAL json_array_elements(t.team -> 'puzzlers') AS elem
    WHERE t.tracked_at >= :since AND t.team IS NOT NULL
        AND elem ->> 'player_id' IS NOT NULL AND elem ->> 'player_id' <> ''
),
results AS ({$results}),
numbered AS (
    SELECT
        r.player_id,
        r.time_id,
        r.solved_at,
        z.pieces_count,
        ROW_NUMBER() OVER w AS position,
        SUM(z.pieces_count) OVER w AS pieces_running
    FROM results r
    JOIN candidates c ON c.player_id = r.player_id
    JOIN player p ON p.id = r.player_id
    JOIN puzzle z ON z.id = r.puzzle_id
    WINDOW w AS (PARTITION BY r.player_id ORDER BY r.solved_at, r.tracked_at, r.time_id ROWS UNBOUNDED PRECEDING)
)
SELECT 'puzzles' AS kind, n.player_id, n.time_id, n.solved_at, m.milestone
FROM numbered n
JOIN unnest({$puzzleMilestones}) AS m(milestone) ON n.position = m.milestone
WHERE n.solved_at >= :since

UNION ALL

SELECT 'pieces', n.player_id, n.time_id, n.solved_at, m.milestone
FROM numbered n
JOIN unnest({$piecesMilestones}) AS m(milestone)
    ON n.pieces_running >= m.milestone AND n.pieces_running - n.pieces_count < m.milestone
WHERE n.solved_at >= :since

UNION ALL

SELECT 'first', n.player_id, n.time_id, n.solved_at, NULL
FROM numbered n
WHERE n.position = 1 AND n.solved_at >= :since
SQL;

        /** @var list<array{kind: string, player_id: string, time_id: string, solved_at: string, milestone: null|int|string}> $rows */
        $rows = $this->database->executeQuery($sql, $parameters)->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $milestone = $row['milestone'] === null ? null : (int) $row['milestone'];

            [$type, $key] = match ($row['kind']) {
                'puzzles' => [PlayerMomentType::PuzzlesMilestone, 'puzzles:' . $milestone],
                'pieces' => [PlayerMomentType::PiecesMilestone, 'pieces:' . $milestone],
                default => [PlayerMomentType::FirstResult, 'first'],
            };

            return [
                'player_id' => $row['player_id'],
                'type' => $type->value,
                'dedupe_key' => $key,
                'occurred_at' => $row['solved_at'],
                'solving_time_id' => $row['time_id'],
                'pieces_count' => null,
                'value' => $milestone,
                'previous_value' => null,
            ];
        }, $rows);
    }

    /**
     * @param list<array{player_id: string, type: string, dedupe_key: string, occurred_at: string, solving_time_id: null|string, pieces_count: null|int, value: null|int, previous_value: null|int}> $moments
     */
    private function upsert(array $moments, DateTimeImmutable $now): int
    {
        $written = 0;
        $detectedAt = $now->format('Y-m-d H:i:s');

        foreach (array_chunk($moments, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $parameters = [];

            foreach ($chunk as $moment) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $parameters,
                    Uuid::uuid7()->toString(),
                    $moment['player_id'],
                    $moment['type'],
                    $moment['dedupe_key'],
                    $moment['occurred_at'],
                    $moment['solving_time_id'],
                    $moment['pieces_count'],
                    $moment['value'],
                    $moment['previous_value'],
                    $detectedAt,
                );
            }

            $sql = 'INSERT INTO player_moment (id, player_id, type, dedupe_key, occurred_at, solving_time_id, pieces_count, value, previous_value, detected_at) VALUES '
                . implode(', ', $values)
                . ' ON CONFLICT (player_id, dedupe_key) DO UPDATE SET
                    type = EXCLUDED.type,
                    occurred_at = EXCLUDED.occurred_at,
                    solving_time_id = EXCLUDED.solving_time_id,
                    pieces_count = EXCLUDED.pieces_count,
                    value = EXCLUDED.value,
                    previous_value = EXCLUDED.previous_value,
                    detected_at = EXCLUDED.detected_at
                WHERE (player_moment.type, player_moment.occurred_at, player_moment.solving_time_id, player_moment.pieces_count, player_moment.value, player_moment.previous_value)
                    IS DISTINCT FROM (EXCLUDED.type, EXCLUDED.occurred_at, EXCLUDED.solving_time_id, EXCLUDED.pieces_count, EXCLUDED.value, EXCLUDED.previous_value)';

            $written += (int) $this->database->executeStatement($sql, $parameters);
        }

        return $written;
    }
}
