<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Doctrine;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * Serialises the saves of one id that the client chose (the add form's `time_id` / `new_puzzle_id`, the API's
 * Idempotency-Key): docs/features/duplicate-results.md, Layer 1.
 *
 * Two requests with the same id in flight at once both looked the id up before either had committed, both went on,
 * and the second one's flush hit the primary key - an error page for a result that was saved (and a closed entity
 * manager). With the lock the second request waits until the first commits and then finds the row like any resend.
 *
 * Native SQL on purpose: a transaction-scoped Postgres advisory lock has no ORM equivalent, and it must be the
 * handler's first statement, inside the transaction the `doctrine_transaction` middleware opened - it is released
 * by that transaction's commit or rollback, nothing has to unlock it.
 */
readonly final class IdLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function lockUntilCommit(UuidInterface $id): void
    {
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:id, 0))',
            ['id' => $id->toString()],
        );
    }
}
