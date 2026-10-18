<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;

/**
 * Brings a batch-computed table to a freshly computed set of rows, writing only the difference - the rule of the
 * insights batch (docs/features/puzzle-intelligence/README.md, "Writes: only what changed").
 *
 * The stored rows of a scope are read and compared in PHP, value by value and exactly (a double read back from
 * Postgres is the very same double); only new and changed rows are formatted and upserted, rows of the scope the run
 * did not produce are deleted. An unchanged row is neither written nor locked, its computed_at stays the moment its
 * values last changed, and a run that changes nothing writes nothing - no temporary table either (its catalog rows
 * would cost WAL on every run). Every table synced this way has its unique key as primary key and a computed_at column;
 * nothing else (no generated id).
 */
readonly final class ChangedRowsSync
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, string> $keyColumns the primary key, column => SQL type
     * @param array<string, string> $valueColumns column => SQL type (uuid, integer, smallint, double precision, boolean, varchar, timestamp, jsonb)
     */
    public function open(string $table, array $keyColumns, array $valueColumns): ChangedRowsSyncRun
    {
        return new ChangedRowsSyncRun($this->connection, $table, $keyColumns, $valueColumns);
    }
}
