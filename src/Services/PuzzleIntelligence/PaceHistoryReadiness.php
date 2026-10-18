<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;

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
    ) {
    }

    public function isBuilt(): bool
    {
        return (bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM pace_history_build)');
    }

    /**
     * After a sync of every player. Written once - later runs leave the row (and the WAL) alone.
     */
    public function markBuilt(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO pace_history_build (id, version, built_at) VALUES (1, :version, NOW()) ON CONFLICT (id) DO NOTHING',
            ['version' => PlayerPaceHistoryCalculator::VERSION],
        );
    }
}
