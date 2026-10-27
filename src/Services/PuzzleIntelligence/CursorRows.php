<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * A large statement's rows through a server-side cursor, a chunk at a time. pdo_pgsql holds a whole result in the
 * client library's memory (outside PHP's memory_limit, but inside the container's): 300k rows of the qualifying first
 * attempts cost ~80 MB of resident memory next to the arrays built from them. A cursor needs a transaction - inside one
 * it uses it, else it opens its own (reads only) and ends it after the last row.
 */
final readonly class CursorRows
{
    /**
     * Rows a fetch: each fetch's rows become PHP arrays and are freed again, among what is built from them - 20,000 a
     * fetch left the daily fit ~20 MB more resident than 5,000 (the freed rows fragment PHP's allocator), in the same time
     */
    public const int CHUNK = 5000;

    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     * @return iterable<array<string, mixed>>
     */
    public static function iterate(Connection $connection, string $sql, array $params = [], array $types = [], int $chunk = self::CHUNK): iterable
    {
        $ownTransaction = !$connection->isTransactionActive();

        if ($ownTransaction) {
            $connection->beginTransaction();
        }

        $cursor = 'candidate_rows_' . bin2hex(random_bytes(4));

        try {
            $connection->executeStatement("DECLARE {$cursor} NO SCROLL CURSOR FOR {$sql}", $params, $types);

            do {
                $rows = $connection->fetchAllAssociative("FETCH FORWARD {$chunk} FROM {$cursor}");

                foreach ($rows as $row) {
                    yield $row;
                }
            } while (count($rows) === $chunk);

            $connection->executeStatement("CLOSE {$cursor}");
        } finally {
            if ($ownTransaction && $connection->isTransactionActive()) {
                $connection->commit();
            }
        }
    }
}
