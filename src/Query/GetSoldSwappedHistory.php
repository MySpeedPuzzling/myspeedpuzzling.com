<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SoldSwappedItemOverview;
use SpeedPuzzling\Web\Value\ListingType;

readonly final class GetSoldSwappedHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SoldSwappedItemOverview>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ssi.id as sold_swapped_item_id,
    ssi.listing_type,
    ssi.price,
    ssi.buyer_name,
    ssi.sold_at,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.identification_number as puzzle_identification_number,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name,
    bp.id as buyer_player_id,
    bp.name as buyer_player_name,
    bp.code as buyer_player_code
FROM sold_swapped_item ssi
JOIN puzzle p ON ssi.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
LEFT JOIN player bp ON ssi.buyer_player_id = bp.id
WHERE ssi.seller_id = :playerId
ORDER BY ssi.sold_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SoldSwappedItemOverview {
            /** @var array{
             *     sold_swapped_item_id: string,
             *     listing_type: string,
             *     price: string|null,
             *     buyer_name: string|null,
             *     sold_at: string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: string|null,
             *     puzzle_identification_number: string|null,
             *     pieces_count: int,
             *     image: string|null,
             *     manufacturer_name: string|null,
             *     buyer_player_id: string|null,
             *     buyer_player_name: string|null,
             *     buyer_player_code: string|null,
             * } $row
             */

            return new SoldSwappedItemOverview(
                soldSwappedItemId: $row['sold_swapped_item_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                puzzleIdentificationNumber: $row['puzzle_identification_number'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                listingType: ListingType::from($row['listing_type']),
                price: $row['price'] !== null ? (float) $row['price'] : null,
                buyerPlayerId: $row['buyer_player_id'],
                buyerPlayerName: $row['buyer_player_name'],
                buyerPlayerCode: $row['buyer_player_code'],
                buyerName: $row['buyer_name'],
                soldAt: new DateTimeImmutable($row['sold_at']),
            );
        }, $data);
    }

    public function countByPlayerId(string $playerId): int
    {
        $query = <<<SQL
SELECT COUNT(*) as item_count
FROM sold_swapped_item
WHERE seller_id = :playerId
SQL;

        $result = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }
}
