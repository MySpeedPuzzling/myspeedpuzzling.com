<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzlerOffer;
use SpeedPuzzling\Web\Results\SellSwapListItemOverview;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class GetSellSwapListItems
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SellSwapListItemOverview>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ssli.id as sell_swap_list_item_id,
    ssli.listing_type,
    ssli.price,
    ssli.condition,
    ssli.comment,
    ssli.added_at,
    ssli.reserved,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.identification_number as puzzle_identification_number,
    p.ean,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name
FROM sell_swap_list_item ssli
JOIN puzzle p ON ssli.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE ssli.player_id = :playerId
ORDER BY ssli.added_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SellSwapListItemOverview {
            /** @var array{
             *     sell_swap_list_item_id: string,
             *     listing_type: string,
             *     price: string|null,
             *     condition: string,
             *     comment: string|null,
             *     added_at: string,
             *     reserved: bool,
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

            return new SellSwapListItemOverview(
                sellSwapListItemId: $row['sell_swap_list_item_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                puzzleIdentificationNumber: $row['puzzle_identification_number'],
                ean: $row['ean'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                listingType: ListingType::from($row['listing_type']),
                price: $row['price'] !== null ? (float) $row['price'] : null,
                condition: PuzzleCondition::from($row['condition']),
                comment: $row['comment'],
                addedAt: new DateTimeImmutable($row['added_at']),
                reserved: (bool) $row['reserved'],
            );
        }, $data);
    }

    public function countByPlayerId(string $playerId): int
    {
        $query = <<<SQL
SELECT COUNT(*) as item_count
FROM sell_swap_list_item
WHERE player_id = :playerId
SQL;

        $result = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function isPuzzleInSellSwapList(string $playerId, string $puzzleId): bool
    {
        $query = <<<SQL
SELECT COUNT(*) as count
FROM sell_swap_list_item
WHERE player_id = :playerId AND puzzle_id = :puzzleId
SQL;

        $result = $this->database
            ->executeQuery($query, ['playerId' => $playerId, 'puzzleId' => $puzzleId])
            ->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }

    /**
     * @return array<PuzzlerOffer>
     */
    public function byPuzzleId(string $puzzleId): array
    {
        $query = <<<SQL
SELECT
    ssli.id as sell_swap_list_item_id,
    ssli.listing_type,
    ssli.price,
    ssli.condition,
    ssli.comment,
    ssli.added_at,
    ssli.reserved,
    pl.id as player_id,
    pl.name as player_name,
    pl.code as player_code,
    pl.avatar as player_avatar,
    pl.country as player_country,
    pl.sell_swap_list_settings
FROM sell_swap_list_item ssli
JOIN player pl ON ssli.player_id = pl.id
WHERE ssli.puzzle_id = :puzzleId
ORDER BY ssli.added_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['puzzleId' => $puzzleId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzlerOffer {
            /** @var array{
             *     sell_swap_list_item_id: string,
             *     listing_type: string,
             *     price: string|null,
             *     condition: string,
             *     comment: string|null,
             *     added_at: string,
             *     reserved: bool,
             *     player_id: string,
             *     player_name: string|null,
             *     player_code: string,
             *     player_avatar: string|null,
             *     player_country: string|null,
             *     sell_swap_list_settings: string|null,
             * } $row
             */

            $currency = null;
            $customCurrency = null;
            if ($row['sell_swap_list_settings'] !== null) {
                /** @var array{currency?: string|null, custom_currency?: string|null} $settings */
                $settings = json_decode($row['sell_swap_list_settings'], true);
                $currency = $settings['currency'] ?? null;
                $customCurrency = $settings['custom_currency'] ?? null;
            }

            return new PuzzlerOffer(
                sellSwapListItemId: $row['sell_swap_list_item_id'],
                listingType: ListingType::from($row['listing_type']),
                price: $row['price'] !== null ? (float) $row['price'] : null,
                condition: PuzzleCondition::from($row['condition']),
                comment: $row['comment'],
                addedAt: new DateTimeImmutable($row['added_at']),
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                playerAvatar: $row['player_avatar'],
                playerCountry: $row['player_country'],
                currency: $currency,
                customCurrency: $customCurrency,
                reserved: (bool) $row['reserved'],
            );
        }, $data);
    }

    public function countByPuzzleId(string $puzzleId): int
    {
        $query = <<<SQL
SELECT COUNT(*) as item_count
FROM sell_swap_list_item
WHERE puzzle_id = :puzzleId
SQL;

        $result = $this->database
            ->executeQuery($query, ['puzzleId' => $puzzleId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }
}
