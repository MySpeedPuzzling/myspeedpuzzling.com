<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\Export\ExportableCollection;

/**
 * The player's custom collections, private included - own data, exported whether or not the membership that
 * created them is still active (docs/features/data-export.md). The system collection has no row; item counts
 * come from the exported items.
 */
readonly final class GetExportableCollections
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ExportableCollection>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    c.id AS collection_id,
    c.name,
    c.description,
    c.visibility,
    c.created_at
FROM collection c
WHERE c.player_id = :playerId
ORDER BY c.created_at, c.id
SQL;

        /** @var list<array{collection_id: string, name: string, description: null|string, visibility: string, created_at: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(ExportableCollection::fromDatabaseRow(...), $rows);
    }
}
