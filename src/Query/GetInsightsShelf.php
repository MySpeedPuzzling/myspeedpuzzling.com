<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Insights\InsightsPuzzleRef;
use SpeedPuzzling\Web\Results\Insights\ShelfPuzzle;

/**
 * The player's shelf for Insights (rating first tries, shelf picks, the re-solve radar's "on your shelf"): a puzzle is
 * on the shelf when it is in one of the player's collections (the system one included) and not lent out, or borrowed
 * by the player - the Puzzle Picker's "mine" (docs/features/insights/computation.md §4c.1).
 */
readonly final class GetInsightsShelf
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * SQL condition: puzzle `$puzzleColumn` is on the shelf of the player bound as `$playerParameter`.
     */
    public static function sqlOnShelf(string $puzzleColumn, string $playerParameter = ':playerId'): string
    {
        return "(
            (EXISTS (SELECT 1 FROM collection_item shelf_ci WHERE shelf_ci.player_id = {$playerParameter} AND shelf_ci.puzzle_id = {$puzzleColumn})
                AND NOT EXISTS (SELECT 1 FROM lent_puzzle shelf_out WHERE shelf_out.owner_player_id = {$playerParameter} AND shelf_out.puzzle_id = {$puzzleColumn} AND shelf_out.current_holder_player_id IS DISTINCT FROM {$playerParameter}))
            OR EXISTS (SELECT 1 FROM lent_puzzle shelf_in WHERE shelf_in.current_holder_player_id = {$playerParameter} AND shelf_in.puzzle_id = {$puzzleColumn})
        )";
    }

    /**
     * Shelf puzzles without any result of the player (solo, or in a pair/team - a suspicious or untimed one counts too:
     * the player has done the puzzle), not secret.
     *
     * @return list<ShelfPuzzle>
     */
    public function unsolved(string $playerId): array
    {
        $puzzleColumns = InsightsPuzzleRef::sqlColumns();
        $secret = InsightsPuzzleRef::sqlSecret();

        $query = <<<SQL
WITH shelf AS (
    SELECT ci.puzzle_id
    FROM collection_item ci
    WHERE ci.player_id = :playerId
        AND NOT EXISTS (SELECT 1 FROM lent_puzzle lp WHERE lp.owner_player_id = :playerId AND lp.puzzle_id = ci.puzzle_id AND lp.current_holder_player_id IS DISTINCT FROM :playerId)
    UNION
    SELECT lp.puzzle_id
    FROM lent_puzzle lp
    WHERE lp.current_holder_player_id = :playerId
),
done AS MATERIALIZED (
    SELECT own.puzzle_id
    FROM puzzle_solving_time own
    WHERE own.player_id = :playerId
    UNION
    SELECT together.puzzle_id
    FROM puzzling_team_member me
    JOIN puzzle_solving_time together ON together.puzzling_team_id = me.team_id
    WHERE me.player_id = :playerId
)
SELECT
    {$puzzleColumns},
    CASE WHEN pd.confidence <> 'insufficient' THEN pd.difficulty_score END AS difficulty_score,
    CASE WHEN pd.confidence <> 'insufficient' THEN pd.difficulty_tier END AS difficulty_tier,
    ps.median_time_solo,
    COALESCE(ps.solved_times_solo_count, 0) AS solo_count
FROM shelf
JOIN puzzle p ON p.id = shelf.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
WHERE NOT {$secret}
    AND NOT EXISTS (SELECT 1 FROM done WHERE done.puzzle_id = p.id)
ORDER BY p.id
SQL;

        /** @var list<array{puzzle_id: string, puzzle_name: string, puzzle_image: null|string, brand_name: null|string, pieces_count: int|string, puzzle_secret: bool|int|string, difficulty_score: null|float|string, difficulty_tier: null|int|string, median_time_solo: null|int|string, solo_count: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $shelf = [];

        foreach ($rows as $row) {
            $puzzle = InsightsPuzzleRef::fromRow($row);

            if ($puzzle === null) {
                continue;
            }

            $shelf[] = new ShelfPuzzle(
                puzzle: $puzzle,
                difficulty: $row['difficulty_score'] !== null ? (float) $row['difficulty_score'] : null,
                difficultyTier: $row['difficulty_tier'] !== null ? (int) $row['difficulty_tier'] : null,
                medianSoloSeconds: $row['median_time_solo'] !== null ? (int) $row['median_time_solo'] : null,
                soloResultsCount: (int) $row['solo_count'],
            );
        }

        return $shelf;
    }

    /**
     * Every public player's first-attempt solo time on each puzzle (their best first-attempt row), ascending - what
     * "faster than N % of its solvers" counts against. Suspicious times left out like on the leaderboard; private
     * players left out like in the rating's lists.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, list<int>>
     */
    public function firstAttemptTimes(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<array{puzzle_id: string, times: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            "SELECT per_player.puzzle_id, json_agg(per_player.seconds ORDER BY per_player.seconds) AS times
            FROM (
                SELECT pst.puzzle_id, MIN(pst.seconds_to_solve) AS seconds
                FROM puzzle_solving_time pst
                JOIN player solver ON solver.id = pst.player_id
                WHERE pst.puzzle_id IN (:puzzleIds)
                    AND pst.first_attempt = true
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                    AND solver.is_private = false
                GROUP BY pst.puzzle_id, pst.player_id
            ) per_player
            GROUP BY per_player.puzzle_id",
            ['puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        );

        $times = [];

        foreach ($rows as $row) {
            /** @var list<int> $list */
            $list = json_decode($row['times'], true, flags: JSON_THROW_ON_ERROR);
            $times[$row['puzzle_id']] = $list;
        }

        return $times;
    }
}
