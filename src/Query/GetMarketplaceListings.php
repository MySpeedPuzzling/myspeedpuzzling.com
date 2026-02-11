<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class GetMarketplaceListings
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<MarketplaceListingItem>
     */
    public function search(
        null|string $searchTerm = null,
        null|string $manufacturerId = null,
        null|int $piecesMin = null,
        null|int $piecesMax = null,
        null|ListingType $listingType = null,
        null|float $priceMin = null,
        null|float $priceMax = null,
        null|PuzzleCondition $condition = null,
        null|string $shipsToCountry = null,
        string $sort = 'newest',
        int $limit = 24,
        int $offset = 0,
    ): array {
        $hasSearch = $searchTerm !== null && $searchTerm !== '';
        $eanSearch = $hasSearch ? trim($searchTerm, '0') : '';

        $query = 'SELECT
    ssli.id AS item_id,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.alternative_name AS puzzle_alternative_name,
    p.pieces_count,
    p.image AS puzzle_image,
    m.name AS manufacturer_name,
    ssli.listing_type,
    ssli.price,
    ssli.condition,
    ssli.comment,
    ssli.reserved,
    ssli.added_at,
    pl.id AS seller_id,
    pl.name AS seller_name,
    pl.code AS seller_code,
    pl.avatar AS seller_avatar,
    pl.country AS seller_country,
    pl.sell_swap_list_settings';

        if ($hasSearch && $sort === 'relevance') {
            $query .= ',
    CASE
        WHEN p.alternative_name ILIKE :searchQuery
          OR p.name ILIKE :searchQuery
          OR p.identification_number = :searchQuery
          OR p.ean = :eanSearchQuery THEN 7
        WHEN immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchQuery)
          OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchQuery) THEN 6
        WHEN p.identification_number ILIKE :searchEndLikeQuery
          OR p.identification_number ILIKE :searchStartLikeQuery
          OR p.ean ILIKE :eanSearchEndLikeQuery
          OR p.ean ILIKE :eanSearchStartLikeQuery THEN 5
        WHEN p.alternative_name ILIKE :searchEndLikeQuery
          OR p.alternative_name ILIKE :searchStartLikeQuery
          OR p.name ILIKE :searchEndLikeQuery
          OR p.name ILIKE :searchStartLikeQuery THEN 4
        WHEN immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchEndLikeQuery)
          OR immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchStartLikeQuery)
          OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchEndLikeQuery)
          OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchStartLikeQuery) THEN 3
        WHEN p.identification_number ILIKE :searchFullLikeQuery
          OR p.ean ILIKE :eanSearchFullLikeQuery THEN 2
        WHEN p.alternative_name ILIKE :searchFullLikeQuery
          OR p.name ILIKE :searchFullLikeQuery THEN 1
        ELSE 0
    END AS match_score';
        }

        $query .= '
FROM sell_swap_list_item ssli
JOIN puzzle p ON ssli.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
JOIN player pl ON ssli.player_id = pl.id
WHERE 1=1';

        $params = [];

        if ($hasSearch) {
            $query .= '
    AND (
        p.alternative_name ILIKE :searchFullLikeQuery
        OR p.name ILIKE :searchFullLikeQuery
        OR immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR p.identification_number ILIKE :searchFullLikeQuery
        OR p.ean ILIKE :eanSearchFullLikeQuery
    )';
            $params['searchQuery'] = $searchTerm;
            $params['searchStartLikeQuery'] = '%' . $searchTerm;
            $params['searchEndLikeQuery'] = $searchTerm . '%';
            $params['searchFullLikeQuery'] = '%' . $searchTerm . '%';
            $params['eanSearchQuery'] = $eanSearch;
            $params['eanSearchStartLikeQuery'] = '%' . $eanSearch;
            $params['eanSearchEndLikeQuery'] = $eanSearch . '%';
            $params['eanSearchFullLikeQuery'] = '%' . $eanSearch . '%';
        }

        if ($manufacturerId !== null && $manufacturerId !== '') {
            $query .= '
    AND p.manufacturer_id = :manufacturerId';
            $params['manufacturerId'] = $manufacturerId;
        }

        if ($piecesMin !== null) {
            $query .= '
    AND p.pieces_count >= :piecesMin';
            $params['piecesMin'] = $piecesMin;
        }

        if ($piecesMax !== null) {
            $query .= '
    AND p.pieces_count <= :piecesMax';
            $params['piecesMax'] = $piecesMax;
        }

        if ($listingType !== null) {
            $query .= '
    AND ssli.listing_type = :listingType';
            $params['listingType'] = $listingType->value;
        }

        if ($priceMin !== null) {
            $query .= '
    AND ssli.price >= :priceMin';
            $params['priceMin'] = $priceMin;
        }

        if ($priceMax !== null) {
            $query .= '
    AND ssli.price <= :priceMax';
            $params['priceMax'] = $priceMax;
        }

        if ($condition !== null) {
            $query .= '
    AND ssli.condition = :condition';
            $params['condition'] = $condition->value;
        }

        if ($shipsToCountry !== null && $shipsToCountry !== '') {
            $query .= "
    AND pl.sell_swap_list_settings->'shippingCountries' @> :countryJson";
            $params['countryJson'] = '"' . $shipsToCountry . '"';
        }

        // Sorting
        if ($hasSearch && $sort === 'relevance') {
            $query .= '
ORDER BY match_score DESC, ssli.added_at DESC';
        } elseif ($sort === 'price_asc') {
            $query .= '
ORDER BY ssli.price ASC NULLS LAST, ssli.added_at DESC';
        } elseif ($sort === 'price_desc') {
            $query .= '
ORDER BY ssli.price DESC NULLS LAST, ssli.added_at DESC';
        } else {
            $query .= '
ORDER BY ssli.added_at DESC';
        }

        $query .= '
LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $data = $this->database
            ->executeQuery($query, $params)
            ->fetchAllAssociative();

        return array_map(static function (array $row): MarketplaceListingItem {
            /**
             * @var array{
             *     item_id: string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: string|null,
             *     pieces_count: int,
             *     puzzle_image: string|null,
             *     manufacturer_name: string|null,
             *     listing_type: string,
             *     price: string|null,
             *     condition: string,
             *     comment: string|null,
             *     reserved: bool,
             *     added_at: string,
             *     seller_id: string,
             *     seller_name: string|null,
             *     seller_code: string|null,
             *     seller_avatar: string|null,
             *     seller_country: string|null,
             *     sell_swap_list_settings: string|null,
             * } $row
             */

            $currency = null;
            $customCurrency = null;
            $shippingCost = null;
            if ($row['sell_swap_list_settings'] !== null) {
                /** @var array{currency?: string|null, customCurrency?: string|null, shippingCost?: string|null} $settings */
                $settings = json_decode($row['sell_swap_list_settings'], true);
                $currency = $settings['currency'] ?? null;
                $customCurrency = $settings['customCurrency'] ?? null;
                $shippingCost = $settings['shippingCost'] ?? null;
            }

            return new MarketplaceListingItem(
                itemId: $row['item_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                piecesCount: (int) $row['pieces_count'],
                puzzleImage: $row['puzzle_image'],
                manufacturerName: $row['manufacturer_name'],
                listingType: $row['listing_type'],
                price: $row['price'] !== null ? (float) $row['price'] : null,
                condition: $row['condition'],
                comment: $row['comment'],
                reserved: (bool) $row['reserved'],
                addedAt: $row['added_at'],
                sellerId: $row['seller_id'],
                sellerName: $row['seller_name'],
                sellerCode: $row['seller_code'],
                sellerAvatar: $row['seller_avatar'],
                sellerCountry: $row['seller_country'],
                sellerCurrency: $currency,
                sellerCustomCurrency: $customCurrency,
                sellerShippingCost: $shippingCost,
            );
        }, $data);
    }

    public function count(
        null|string $searchTerm = null,
        null|string $manufacturerId = null,
        null|int $piecesMin = null,
        null|int $piecesMax = null,
        null|ListingType $listingType = null,
        null|float $priceMin = null,
        null|float $priceMax = null,
        null|PuzzleCondition $condition = null,
        null|string $shipsToCountry = null,
    ): int {
        $hasSearch = $searchTerm !== null && $searchTerm !== '';
        $eanSearch = $hasSearch ? trim($searchTerm, '0') : '';

        $query = 'SELECT COUNT(*)
FROM sell_swap_list_item ssli
JOIN puzzle p ON ssli.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
JOIN player pl ON ssli.player_id = pl.id
WHERE 1=1';

        $params = [];

        if ($hasSearch) {
            $query .= '
    AND (
        p.alternative_name ILIKE :searchFullLikeQuery
        OR p.name ILIKE :searchFullLikeQuery
        OR immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR p.identification_number ILIKE :searchFullLikeQuery
        OR p.ean ILIKE :eanSearchFullLikeQuery
    )';
            $params['searchFullLikeQuery'] = '%' . $searchTerm . '%';
            $params['eanSearchFullLikeQuery'] = '%' . $eanSearch . '%';
        }

        if ($manufacturerId !== null && $manufacturerId !== '') {
            $query .= '
    AND p.manufacturer_id = :manufacturerId';
            $params['manufacturerId'] = $manufacturerId;
        }

        if ($piecesMin !== null) {
            $query .= '
    AND p.pieces_count >= :piecesMin';
            $params['piecesMin'] = $piecesMin;
        }

        if ($piecesMax !== null) {
            $query .= '
    AND p.pieces_count <= :piecesMax';
            $params['piecesMax'] = $piecesMax;
        }

        if ($listingType !== null) {
            $query .= '
    AND ssli.listing_type = :listingType';
            $params['listingType'] = $listingType->value;
        }

        if ($priceMin !== null) {
            $query .= '
    AND ssli.price >= :priceMin';
            $params['priceMin'] = $priceMin;
        }

        if ($priceMax !== null) {
            $query .= '
    AND ssli.price <= :priceMax';
            $params['priceMax'] = $priceMax;
        }

        if ($condition !== null) {
            $query .= '
    AND ssli.condition = :condition';
            $params['condition'] = $condition->value;
        }

        if ($shipsToCountry !== null && $shipsToCountry !== '') {
            $query .= "
    AND pl.sell_swap_list_settings->'shippingCountries' @> :countryJson";
            $params['countryJson'] = '"' . $shipsToCountry . '"';
        }

        $count = $this->database
            ->executeQuery($query, $params)
            ->fetchOne();

        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    /**
     * @return array<array{manufacturer_id: string, manufacturer_name: string, listing_count: int}>
     */
    public function getManufacturersWithActiveListings(): array
    {
        $query = <<<SQL
SELECT
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    COUNT(*) AS listing_count
FROM sell_swap_list_item ssli
JOIN puzzle p ON ssli.puzzle_id = p.id
JOIN manufacturer m ON p.manufacturer_id = m.id
GROUP BY m.id, m.name
HAVING COUNT(*) > 0
ORDER BY m.name ASC
SQL;

        /** @var array<array{manufacturer_id: string, manufacturer_name: string, listing_count: int}> $rows */
        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return $rows;
    }
}
