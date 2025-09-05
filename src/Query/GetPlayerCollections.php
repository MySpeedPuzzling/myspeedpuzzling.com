<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class GetPlayerCollections
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<CollectionOverview>
     */
    public function byPlayerId(string $playerId, bool $includePrivate = true): array
    {
        $visibilityCondition = $includePrivate ? '' : "AND visibility = 'public'";

        $query = <<<SQL
SELECT id, name, description, visibility, created_at
FROM collection
WHERE player_id = :playerId {$visibilityCondition}
ORDER BY created_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CollectionOverview {
            /** @var array{
             *     id: string,
             *     name: string,
             *     description: string|null,
             *     visibility: string,
             *     created_at: string,
             * } $row
             */

            return new CollectionOverview(
                collectionId: $row['id'],
                name: $row['name'],
                description: $row['description'],
                visibility: CollectionVisibility::from($row['visibility']),
                createdAt: new DateTimeImmutable($row['created_at']),
            );
        }, $data);
    }
}
