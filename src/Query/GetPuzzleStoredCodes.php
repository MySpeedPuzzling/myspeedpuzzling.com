<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Every puzzle's codes as stored, for myspeedpuzzling:canonicalize-puzzle-codes - in batches by id (keyset, so a batch
 * costs the same at the end of the table as at its start).
 */
readonly final class GetPuzzleStoredCodes
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function count(): int
    {
        $count = $this->database->executeQuery('SELECT COUNT(*) FROM puzzle')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The next puzzles by id (with or without codes, so the keyset moves over all of them).
     *
     * @return list<array{id: string, name: string, ean: null|string, identification_number: null|string}>
     */
    public function after(null|string $afterId, int $limit): array
    {
        $query = <<<SQL
SELECT id, name, ean, identification_number
FROM puzzle
WHERE id > :afterId
ORDER BY id
LIMIT :limit
SQL;

        /** @var list<array{id: string, name: string, ean: null|string, identification_number: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'afterId' => $afterId ?? '00000000-0000-0000-0000-000000000000',
                'limit' => max(1, $limit),
            ])
            ->fetchAllAssociative();

        return $rows;
    }
}
