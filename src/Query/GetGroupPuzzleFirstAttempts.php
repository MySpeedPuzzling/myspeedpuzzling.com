<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CursorRows;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;

/**
 * The qualifying first attempts (PlayerBaselineCalculator's one rule - what the shown difficulty is computed from) of
 * every puzzle that has a pair/team result, a puzzle at a time - what the pair/team model's as-of difficulty is built
 * from (Group\GroupDifficultyTimeline, docs/features/puzzle-intelligence/README.md "Pair and team predictions").
 * Through a cursor: only the puzzles' index events are kept, never the rows.
 */
readonly final class GetGroupPuzzleFirstAttempts
{
    public function __construct(
        private Connection $connection,
        private PlayerBaselineCalculator $baselineCalculator,
    ) {
    }

    /**
     * @return iterable<array{player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string}> by puzzle
     */
    public function rows(): iterable
    {
        [$qualifying, $params, $types] = $this->baselineCalculator->qualifyingFirstAttemptsQuery();

        /** @var iterable<array{player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string}> $rows */
        $rows = CursorRows::iterate($this->connection, "
            SELECT
                q.player_id,
                q.puzzle_id,
                q.pieces_count,
                q.seconds_to_solve,
                EXTRACT(EPOCH FROM q.solve_date) AS solved_ts,
                EXTRACT(EPOCH FROM q.tracked_at) AS tracked_ts
            FROM ({$qualifying}) q
            WHERE q.puzzle_id IN (SELECT g.puzzle_id FROM puzzle_solving_time g WHERE g.puzzling_type IN ('duo', 'team'))
            ORDER BY q.puzzle_id
        ", $params, $types);

        return $rows;
    }
}
