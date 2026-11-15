<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Doctrine\DBAL\Connection;

/**
 * One writer of imported results per player at a time (docs/features/data-import/writer.md "Never twice"): the
 * check "did this row, or a row with the same key, make a result already?" and the write that follows it must not
 * interleave with another delivery of the same row or with the same data arriving through another import.
 *
 * Native SQL on purpose, like IdLock: a transaction-scoped Postgres advisory lock has no ORM equivalent. It is
 * released by the commit or rollback of the transaction the caller's handler runs in.
 */
readonly final class ImportWriteLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function lockUntilCommit(string $playerId): void
    {
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'import-result:' . strtolower($playerId)],
        );
    }
}
