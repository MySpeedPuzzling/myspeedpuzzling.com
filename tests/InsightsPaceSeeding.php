<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Pace history rows written by hand (player_pace_history + the pace_history_build marker), for tests of what reads
 * paces as of a solve - the coordination fit, the synergy, the improvement curve. Each row needs a solving time to hang
 * on (time_id); a solo time on a puzzle of its own is inserted for it.
 */
trait InsightsPaceSeeding
{
    private function markPaceHistoryBuilt(Connection $connection, float $scalingExponent = 1.33): void
    {
        $connection->executeStatement(
            'INSERT INTO pace_history_build (id, version, built_at, scaling_exponent) VALUES (1, 1, ' . TestClock::SQL_NOW . ', :exponent)
            ON CONFLICT (id) DO UPDATE SET scaling_exponent = EXCLUDED.scaling_exponent',
            ['exponent' => $scalingExponent],
        );
    }

    /**
     * A pace row of the player valid from $validFrom on; returns the id of the solving time it hangs on.
     */
    private function paceRow(
        Connection $connection,
        string $playerId,
        int $piecesCount,
        DateTimeImmutable $validFrom,
        int $baselineSeconds,
        int $qualifyingCount,
        null|float $step = null,
        null|string $puzzleId = null,
    ): string {
        if ($puzzleId === null) {
            $puzzleId = Uuid::uuid7()->toString();
            $connection->executeStatement(
                "INSERT INTO puzzle (id, pieces_count, name, approved, is_available) VALUES (:id, :pieces, 'Pace puzzle', true, true)",
                ['id' => $puzzleId, 'pieces' => $piecesCount],
            );
        }

        $timeId = Uuid::uuid7()->toString();
        $at = $validFrom->format('Y-m-d H:i:s');

        $connection->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type, puzzlers_count)
            VALUES (:id, :player, :puzzle, :seconds, :at, :at, true, true, 'solo', 1)",
            ['id' => $timeId, 'player' => $playerId, 'puzzle' => $puzzleId, 'seconds' => $baselineSeconds, 'at' => $at],
        );
        $connection->executeStatement(
            'INSERT INTO player_pace_history (id, player_id, pieces_count, valid_from, valid_from_tracked_at, time_id, baseline_seconds, qualifying_count, step, drift, inputs_checksum)
            VALUES (:id, :player, :pieces, :at, :at, :time, :baseline, :count, :step, NULL, 0)',
            [
                'id' => Uuid::uuid7()->toString(),
                'player' => $playerId,
                'pieces' => $piecesCount,
                'at' => $at,
                'time' => $timeId,
                'baseline' => $baselineSeconds,
                'count' => $qualifyingCount,
                'step' => $step,
            ],
        );

        return $timeId;
    }
}
