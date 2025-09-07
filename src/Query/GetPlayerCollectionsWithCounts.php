<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CollectionOverviewWithCount;
use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class GetPlayerCollectionsWithCounts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function countSystemCollection(string $playerId): int
    {
        $systemCountQuery = <<<SQL
SELECT COUNT(*) as item_count
FROM collection_item
WHERE player_id = :playerId AND collection_id IS NULL
SQL;

        $systemCountResult = $this->database
            ->executeQuery($systemCountQuery, [
                'playerId' => $playerId,
            ])
            ->fetchOne();

        return is_numeric($systemCountResult) ? (int) $systemCountResult : 0;
    }

    /**
     * @return array<CollectionOverviewWithCount>
     */
    public function byPlayerId(string $playerId, bool $includePrivate = true): array
    {
        $visibilityCondition = $includePrivate ? '' : "AND c.visibility = 'public'";

        $query = <<<SQL
SELECT 
    c.id, 
    c.name, 
    c.description, 
    c.visibility, 
    c.created_at,
    COALESCE(ci_counts.item_count, 0) as item_count
FROM collection c
LEFT JOIN (
    SELECT collection_id, COUNT(*) as item_count
    FROM collection_item
    WHERE player_id = :playerId
    GROUP BY collection_id
) ci_counts ON c.id = ci_counts.collection_id
WHERE c.player_id = :playerId {$visibilityCondition}
ORDER BY c.created_at DESC
SQL;

        $collections = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        $result = [];

        // Add regular collections
        foreach ($collections as $row) {
            /** @var array{
             *     id: string,
             *     name: string,
             *     description: string|null,
             *     visibility: string,
             *     created_at: string,
             *     item_count: int,
             * } $row
             */

            $result[] = new CollectionOverviewWithCount(
                collectionId: $row['id'],
                name: $row['name'],
                description: $row['description'],
                visibility: CollectionVisibility::from($row['visibility']),
                createdAt: new DateTimeImmutable($row['created_at']),
                itemCount: $row['item_count'],
                isSystemCollection: false,
            );
        }

        return $result;
    }
}
