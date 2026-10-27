<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use Doctrine\DBAL\Connection;

/**
 * Solo first tries solved unboxed (without the picture): the player's first solo solve of the puzzle, not suspicious.
 * The qualifying rule leaves them out of paces and difficulty, the shown model predicts them like any first try - they
 * come ~8 % slower within player and only 29 % land in the 50 % window (data-mining review F6). The candidate model (v4)
 * fits its unboxed offset and window scale on them (CandidateModelFitter), column by column.
 */
final class UnboxedFirstTries
{
    /** @var list<string> */
    public array $player = [];
    /** @var list<string> */
    public array $puzzle = [];
    /** @var list<int> */
    public array $pieces = [];
    /** @var list<int> */
    public array $seconds = [];
    /** @var list<float> */
    public array $solved = [];
    /** @var list<float> */
    public array $tracked = [];
    /** @var list<bool> */
    public array $competition = [];

    public static function load(Connection $connection): self
    {
        $tries = new self();

        $rows = $connection->executeQuery("
            SELECT pst.player_id, pst.puzzle_id, p.pieces_count, pst.seconds_to_solve, (pst.competition_id IS NOT NULL OR pst.competition_series_id IS NOT NULL) AS competition,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.puzzling_type = 'solo'
                AND pst.unboxed = true
                AND pst.suspicious = false
                AND pst.seconds_to_solve > 0
                AND NOT EXISTS (
                    SELECT 1 FROM puzzle_solving_time earlier
                    WHERE earlier.player_id = pst.player_id
                        AND earlier.puzzle_id = pst.puzzle_id
                        AND earlier.puzzling_type = 'solo'
                        AND (COALESCE(earlier.finished_at, earlier.tracked_at), earlier.tracked_at, earlier.id) < (COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id)
                )
        ")->iterateAssociative();

        foreach ($rows as $row) {
            /** @var array{player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, competition: bool, solved_ts: float|string, tracked_ts: float|string} $row */
            $tries->player[] = $row['player_id'];
            $tries->puzzle[] = $row['puzzle_id'];
            $tries->pieces[] = (int) $row['pieces_count'];
            $tries->seconds[] = (int) $row['seconds_to_solve'];
            $tries->solved[] = (float) $row['solved_ts'];
            $tries->tracked[] = (float) $row['tracked_ts'];
            $tries->competition[] = (bool) $row['competition'];
        }

        return $tries;
    }

    public static function none(): self
    {
        return new self();
    }

    public function count(): int
    {
        return count($this->player);
    }
}
