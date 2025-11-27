<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\WishListItemOverview;

readonly final class GetWishListItems
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<WishListItemOverview>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    wli.id as wish_list_item_id,
    wli.remove_on_collection_add,
    wli.added_at,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.identification_number as puzzle_identification_number,
    p.ean,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name
FROM wish_list_item wli
JOIN puzzle p ON wli.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE wli.player_id = :playerId
ORDER BY wli.added_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): WishListItemOverview {
            /** @var array{
             *     wish_list_item_id: string,
             *     remove_on_collection_add: bool,
             *     added_at: string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: string|null,
             *     puzzle_identification_number: string|null,
             *     ean: string|null,
             *     pieces_count: int,
             *     image: string|null,
             *     manufacturer_name: string|null,
             * } $row
             */

            return new WishListItemOverview(
                wishListItemId: $row['wish_list_item_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                puzzleIdentificationNumber: $row['puzzle_identification_number'],
                ean: $row['ean'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                removeOnCollectionAdd: (bool) $row['remove_on_collection_add'],
                addedAt: new DateTimeImmutable($row['added_at']),
            );
        }, $data);
    }

    public function countByPlayerId(string $playerId): int
    {
        $query = <<<SQL
SELECT COUNT(*) as item_count
FROM wish_list_item
WHERE player_id = :playerId
SQL;

        $result = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function isPuzzleInWishList(string $playerId, string $puzzleId): bool
    {
        $query = <<<SQL
SELECT COUNT(*) as count
FROM wish_list_item
WHERE player_id = :playerId AND puzzle_id = :puzzleId
SQL;

        $result = $this->database
            ->executeQuery($query, ['playerId' => $playerId, 'puzzleId' => $puzzleId])
            ->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }
}
