<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CollectionFolderInfo;

readonly final class GetCollectionFolders
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<CollectionFolderInfo>
     */
    public function forPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    cf.id,
    cf.name,
    cf.is_system,
    cf.color,
    cf.description,
    COUNT(ppc.id) as puzzle_count
FROM collection_folder cf
LEFT JOIN player_puzzle_collection ppc ON ppc.folder_id = cf.id
WHERE cf.player_id = :playerId
GROUP BY cf.id, cf.name, cf.is_system, cf.color, cf.description
ORDER BY cf.is_system ASC, cf.name ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        /** @var array<CollectionFolderInfo> $results */
        $results = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     is_system: bool,
             *     color: null|string,
             *     description: null|string,
             *     puzzle_count: int,
             * } $row
             */
            $results[] = new CollectionFolderInfo(
                $row['id'],
                $row['name'],
                $row['is_system'],
                $row['color'],
                $row['description'],
                $row['puzzle_count'],
            );
        }

        return $results;
    }
}