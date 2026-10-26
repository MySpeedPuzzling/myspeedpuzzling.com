<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Whether player_pace_history holds every player's history yet. The migration creates the table
 * empty; until the first full sync (batch or backfill) has finished, nothing may compute a difficulty
 * from it - the incremental handler keeps the stored difficulty, the batch skips the difficulty step
 * of a --player run, the reconstructor leaves statistical predictions pending. One marker row
 * (pace_history_build), written at the end of a complete sync and never removed.
 */
readonly final class PaceHistoryReadiness
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function isBuilt(): bool
    {
        return $this->scalingExponent() !== null;
    }

    /**
     * The scaling exponent of the last full sync; null = the history is not built yet.
     */
    public function scalingExponent(): null|float
    {
        /** @var false|float|int|string $exponent */
        $exponent = $this->connection->fetchOne('SELECT COALESCE(scaling_exponent, ' . PlayerBaselineCalculator::SCALING_EXPONENT_DEFAULT . ') FROM pace_history_build WHERE id = 1');

        return $exponent === false ? null : (float) $exponent;
    }

    /**
     * After a sync of every player, with the baselines' scaling exponent of that moment. The row is
     * inserted once; later runs write only a changed exponent (no WAL otherwise).
     */
    public function markBuilt(float $scalingExponent): void
    {
        $this->connection->executeStatement(
            'INSERT INTO pace_history_build (id, version, built_at, scaling_exponent) VALUES (1, :version, CAST(:now AS timestamp), :exponent)
            ON CONFLICT (id) DO UPDATE SET scaling_exponent = EXCLUDED.scaling_exponent
            WHERE pace_history_build.scaling_exponent IS DISTINCT FROM EXCLUDED.scaling_exponent',
            [
                'version' => PlayerPaceHistoryCalculator::VERSION,
                'exponent' => $scalingExponent,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }
}
