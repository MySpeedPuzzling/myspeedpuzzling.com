<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CollectionItemOverview;

readonly final class GetCollectionItems
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<CollectionItemOverview>
     */
    public function byCollectionAndPlayer(null|string $collectionId, string $playerId): array
    {
        $collectionCondition = $collectionId === null
            ? 'ci.collection_id IS NULL'
            : 'ci.collection_id = :collectionId';

        $params = ['playerId' => $playerId];
        if ($collectionId !== null) {
            $params['collectionId'] = $collectionId;
        }

        $query = <<<SQL
SELECT 
    ci.id as collection_item_id,
    ci.comment,
    ci.added_at,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name
FROM collection_item ci
JOIN puzzle p ON ci.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE ci.player_id = :playerId AND {$collectionCondition}
ORDER BY ci.added_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, $params)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CollectionItemOverview {
            /** @var array{
             *     collection_item_id: string,
             *     comment: string|null,
             *     added_at: string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     pieces_count: int,
             *     image: string|null,
             *     manufacturer_name: string|null,
             * } $row
             */

            return new CollectionItemOverview(
                collectionItemId: $row['collection_item_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                comment: $row['comment'],
                addedAt: new DateTimeImmutable($row['added_at']),
            );
        }, $data);
    }
}
