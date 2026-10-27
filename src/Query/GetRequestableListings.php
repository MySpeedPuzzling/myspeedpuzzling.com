<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\RequestableListing;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * What a buyer may ask a seller for (docs/features/marketplace/12-listing-requests.md) - the one rule, for the request
 * sheet and the AskForListings handler alike. Takes the buyer's id, never a security token. One statement.
 *
 * A listing is listed while it exists, its puzzle is not secret and there is no block between the buyer and the seller,
 * either way - anything else is simply missing, so "unavailable" never tells a block apart. Being published on the
 * marketplace is not required: the seller's own list page shows every listing to visitors and lets them contact the
 * seller about it. The rest is flagged for the sheet and the handler: the buyer's own listing, one reserved for somebody
 * else, and one the buyer already waits for (an open ask - waiting or yes).
 *
 * A private seller the buyer is not allowed to see is named by code only.
 */
readonly final class GetRequestableListings
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $listingIds
     *
     * @return array<string, RequestableListing> by listing id, in the order asked
     */
    public function forListings(string $buyerId, array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter(array_map(strtolower(...), $listingIds), static fn (string $id): bool => Uuid::isValid($id))));

        if ($listingIds === [] || Uuid::isValid($buyerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    ssli.id AS listing_id,
    seller.id AS seller_id,
    CASE WHEN seller_hidden.hidden THEN NULL ELSE seller.name END AS seller_name,
    seller.code AS seller_code,
    CASE WHEN seller_hidden.hidden THEN NULL ELSE seller.avatar END AS seller_avatar,
    CASE WHEN seller_hidden.hidden THEN NULL ELSE seller.country END AS seller_country,
    seller.sell_swap_list_settings,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    m.name AS manufacturer_name,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS image,
    ssli.listing_type,
    ssli.price,
    ssli.condition,
    seller.id = :buyerId AS own,
    (ssli.reserved = true AND (ssli.reserved_for_player_id IS NULL OR ssli.reserved_for_player_id <> :buyerId)) AS reserved_for_somebody_else,
    EXISTS (
        SELECT 1 FROM listing_request_item item
        WHERE item.buyer_id = :buyerId AND item.listing_id = ssli.id AND item.status IN ('waiting', 'yes')
    ) AS already_asked
FROM sell_swap_list_item ssli
JOIN player seller ON seller.id = ssli.player_id
JOIN puzzle p ON p.id = ssli.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
CROSS JOIN LATERAL (
    SELECT seller.is_private AND seller.id <> :buyerId AND NOT EXISTS (
        SELECT 1 FROM private_profile_viewer ppv WHERE ppv.owner_id = seller.id AND ppv.viewer_id = :buyerId
    ) AS hidden
) seller_hidden
WHERE ssli.id = ANY(:listingIds)
    AND (p.hide_until IS NULL OR p.hide_until <= :now::timestamp)
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE (ub.blocker_id = seller.id AND ub.blocked_id = :buyerId)
            OR (ub.blocker_id = :buyerId AND ub.blocked_id = seller.id)
    )
SQL;

        /** @var list<array{listing_id: string, seller_id: string, seller_name: null|string, seller_code: string, seller_avatar: null|string, seller_country: null|string, sell_swap_list_settings: null|string, puzzle_id: string, puzzle_name: string, pieces_count: int, manufacturer_name: null|string, image: null|string, listing_type: string, price: null|float|string, condition: string, own: bool, reserved_for_somebody_else: bool, already_asked: bool}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'buyerId' => $buyerId,
                'listingIds' => '{' . implode(',', $listingIds) . '}',
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        $byId = [];

        foreach ($rows as $row) {
            /** @var array{currency?: null|string, custom_currency?: null|string, shipping_cost?: null|string, shipping_countries?: null|list<string>} $settings */
            $settings = $row['sell_swap_list_settings'] !== null ? json_decode($row['sell_swap_list_settings'], true) : [];
            $currency = $settings['custom_currency'] ?? $settings['currency'] ?? null;

            $byId[$row['listing_id']] = new RequestableListing(
                listingId: $row['listing_id'],
                sellerId: $row['seller_id'],
                sellerName: $row['seller_name'],
                sellerCode: $row['seller_code'],
                sellerAvatar: $row['seller_avatar'],
                sellerCountry: $row['seller_country'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                listingType: ListingType::from($row['listing_type']),
                price: $row['price'] !== null ? (float) $row['price'] : null,
                condition: PuzzleCondition::from($row['condition']),
                currency: $currency !== null && $currency !== '' ? $currency : null,
                shippingCost: $settings['shipping_cost'] ?? null,
                shippingCountries: array_values(array_filter($settings['shipping_countries'] ?? [], is_string(...))),
                own: $row['own'],
                reservedForSomebodyElse: $row['reserved_for_somebody_else'],
                alreadyAsked: $row['already_asked'],
            );
        }

        $ordered = [];

        foreach ($listingIds as $listingId) {
            if (isset($byId[$listingId])) {
                $ordered[$listingId] = $byId[$listingId];
            }
        }

        return $ordered;
    }
}
