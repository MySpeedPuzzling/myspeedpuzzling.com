<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleCollectionOverview;
use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class GetPuzzleCollections
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PuzzleCollectionOverview>
     */
    public function byPlayerAndPuzzle(string $playerId, string $puzzleId): array
    {
        $query = <<<SQL
SELECT 
    ci.id as collection_item_id,
    c.id as collection_id,
    c.name as collection_name,
    c.visibility
FROM collection_item ci
LEFT JOIN collection c ON ci.collection_id = c.id
WHERE ci.player_id = :playerId AND ci.puzzle_id = :puzzleId
ORDER BY 
    CASE WHEN ci.collection_id IS NULL THEN 0 ELSE 1 END,
    c.name ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleCollectionOverview {
            /** @var array{
             *     collection_item_id: string,
             *     collection_id: string|null,
             *     collection_name: string|null,
             *     visibility: string|null,
             * } $row
             */

            return new PuzzleCollectionOverview(
                collectionItemId: $row['collection_item_id'],
                collectionId: $row['collection_id'],
                collectionName: $row['collection_name'] ?? 'Puzzle Collection',
                visibility: $row['visibility'] !== null ? CollectionVisibility::from($row['visibility']) : CollectionVisibility::Private,
            );
        }, $data);
    }
}
