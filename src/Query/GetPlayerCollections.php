<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\CollectionOverview;

readonly final class GetPlayerCollections
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<CollectionOverview>
     * @throws PlayerNotFound
     */
    public function allByPlayer(string $playerId): array
    {
        if (!Uuid::isValid($playerId)) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    c.id,
    c.name,
    c.description,
    c.is_public,
    c.system_type,
    c.created_at,
    c.updated_at,
    COUNT(DISTINCT ci.id) AS puzzles_count
FROM puzzle_collection c
LEFT JOIN puzzle_collection_item ci ON ci.collection_id = c.id
WHERE c.player_id = :playerId
GROUP BY c.id, c.name, c.description, c.is_public, c.system_type, c.created_at, c.updated_at
ORDER BY 
    CASE 
        WHEN c.system_type = 'completed' THEN 1
        WHEN c.system_type = 'wishlist' THEN 2
        WHEN c.system_type = 'todolist' THEN 3
        WHEN c.system_type = 'my_collection' THEN 4
        WHEN c.system_type = 'borrowed_to' THEN 5
        WHEN c.system_type = 'borrowed_from' THEN 6
        WHEN c.system_type = 'for_sale' THEN 7
        WHEN c.system_type IS NULL AND c.name IS NULL THEN 8
        ELSE 9
    END,
    c.created_at DESC
SQL;

        /** @var array<array{
         *     id: string,
         *     name: null|string,
         *     description: null|string,
         *     is_public: bool,
         *     system_type: null|string,
         *     created_at: string,
         *     updated_at: null|string,
         *     puzzles_count: int,
         * }> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['playerId' => $playerId]);

        return array_map(static fn(array $row): CollectionOverview => CollectionOverview::fromDatabaseRow($row), $rows);
    }

    /**
     * @return array<CollectionOverview>
     */
    public function publicByPlayer(string $playerId): array
    {
        if (!Uuid::isValid($playerId)) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    c.id,
    c.name,
    c.description,
    c.is_public,
    c.system_type,
    c.created_at,
    c.updated_at,
    COUNT(DISTINCT ci.id) AS puzzles_count
FROM puzzle_collection c
LEFT JOIN puzzle_collection_item ci ON ci.collection_id = c.id
WHERE c.player_id = :playerId AND c.is_public = true
GROUP BY c.id, c.name, c.description, c.is_public, c.system_type, c.created_at, c.updated_at
ORDER BY 
    CASE 
        WHEN c.system_type = 'completed' THEN 1
        WHEN c.system_type = 'wishlist' THEN 2
        WHEN c.system_type = 'todolist' THEN 3
        WHEN c.system_type = 'my_collection' THEN 4
        WHEN c.system_type = 'for_sale' THEN 5
        WHEN c.system_type IS NULL AND c.name IS NULL THEN 8
        ELSE 9
    END,
    c.created_at DESC
SQL;

        /** @var array<array{
         *     id: string,
         *     name: null|string,
         *     description: null|string,
         *     is_public: bool,
         *     system_type: null|string,
         *     created_at: string,
         *     updated_at: null|string,
         *     puzzles_count: int,
         * }> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['playerId' => $playerId]);

        return array_map(static fn(array $row): CollectionOverview => CollectionOverview::fromDatabaseRow($row), $rows);
    }

    /**
     * @return array<CollectionOverview>
     */
    public function forCollectionSelection(string $playerId): array
    {
        if (!Uuid::isValid($playerId)) {
            throw new PlayerNotFound();
        }

        // Only return collections where user can add puzzles
        $query = <<<SQL
SELECT
    c.id,
    c.name,
    c.description,
    c.is_public,
    c.system_type,
    c.created_at,
    c.updated_at,
    0 AS puzzles_count
FROM puzzle_collection c
WHERE c.player_id = :playerId 
    AND (
        c.system_type IN ('wishlist', 'todolist', 'for_sale')
        OR (c.system_type IS NULL)
    )
ORDER BY 
    CASE 
        WHEN c.system_type = 'wishlist' THEN 1
        WHEN c.system_type = 'todolist' THEN 2
        WHEN c.system_type = 'for_sale' THEN 3
        WHEN c.system_type IS NULL AND c.name IS NULL THEN 4
        ELSE 5
    END,
    c.created_at DESC
SQL;

        /** @var array<array{
         *     id: string,
         *     name: null|string,
         *     description: null|string,
         *     is_public: bool,
         *     system_type: null|string,
         *     created_at: string,
         *     updated_at: null|string,
         *     puzzles_count: int,
         * }> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['playerId' => $playerId]);

        return array_map(static fn(array $row): CollectionOverview => CollectionOverview::fromDatabaseRow($row), $rows);
    }
}
