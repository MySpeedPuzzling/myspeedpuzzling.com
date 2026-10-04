<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Every puzzle for myspeedpuzzling:rebuild-puzzle-search-keys, in batches by id (keyset, so a batch costs the same
 * at the end of the table as at its start), and what is left without a key.
 */
readonly final class GetPuzzlesForSearchKeys
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
     * @return list<string>
     */
    public function idsAfter(null|string $afterId, int $limit): array
    {
        $query = <<<SQL
SELECT id
FROM puzzle
WHERE id > :afterId
ORDER BY id
LIMIT :limit
SQL;

        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery($query, [
                'afterId' => $afterId ?? '00000000-0000-0000-0000-000000000000',
                'limit' => max(1, $limit),
            ])
            ->fetchFirstColumn();

        return $ids;
    }

    /**
     * Puzzles whose name key is missing - every puzzle has a name, so 0 once the keys are built.
     */
    public function countWithoutNameKey(): int
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM puzzle
WHERE search_names IS NULL OR btrim(search_names, E'\n') = ''
SQL;

        $count = $this->database->executeQuery($query)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
