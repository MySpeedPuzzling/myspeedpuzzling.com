<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ListingRequestItemOverview;
use SpeedPuzzling\Web\Results\ListingRequestOfferOverview;
use SpeedPuzzling\Web\Results\ListingRequestOverview;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * Listing requests as their two sides see them (docs/features/marketplace/12-listing-requests.md): "Sent" (the viewer
 * asked; the counterpart is the seller), "Received" (the viewer sells; the counterpart is the buyer) and one request for
 * its page. Only ever the viewer's own requests, one statement each.
 *
 * Whoever the viewer blocks is left out (HiddenPlayers). Who asks is named to the seller even when private - asking was
 * their consent; a private seller the buyer may not see is shown by code. A private counterpart is never shown by their
 * picture.
 */
readonly final class GetListingRequests
{
    private const int LIMIT = 100;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<ListingRequestOverview> newest first
     */
    public function sentBy(string $buyerId): array
    {
        return $this->requests('buyer', 'lr.buyer_id = :viewerId', ['viewerId' => $buyerId]);
    }

    /**
     * @return list<ListingRequestOverview> waiting first, then newest first
     */
    public function receivedBy(string $sellerId): array
    {
        return $this->requests('seller', 'lr.seller_id = :viewerId', ['viewerId' => $sellerId]);
    }

    /**
     * One request for its page - null for anybody but its buyer or seller.
     */
    public function forViewer(string $requestId, string $viewerId): null|ListingRequestOverview
    {
        if (Uuid::isValid($requestId) === false || Uuid::isValid($viewerId) === false) {
            return null;
        }

        $params = ['viewerId' => $viewerId, 'requestId' => $requestId];

        return $this->requests('buyer', 'lr.id = :requestId AND lr.buyer_id = :viewerId', $params)[0]
            ?? $this->requests('seller', 'lr.id = :requestId AND lr.seller_id = :viewerId', $params)[0]
            ?? null;
    }

    /**
     * How many requests wait for the seller's answer - the "Requests (2 waiting)" link on their own sell/swap list.
     */
    public function waitingCountFor(string $sellerId): int
    {
        if (Uuid::isValid($sellerId) === false) {
            return 0;
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('lr.buyer_id');

        $query = <<<SQL
SELECT COUNT(DISTINCT lr.id)
FROM listing_request lr
JOIN listing_request_item item ON item.request_id = lr.id AND item.status = 'waiting'
WHERE lr.seller_id = :sellerId{$notHidden}
SQL;

        $count = $this->database->executeQuery($query, ['sellerId' => $sellerId])->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param 'buyer'|'seller' $viewerRole
     * @param array<string, string> $params
     * @return list<ListingRequestOverview>
     */
    private function requests(string $viewerRole, string $condition, array $params): array
    {
        if (Uuid::isValid($params['viewerId']) === false) {
            return [];
        }

        $counterpartColumn = $viewerRole === 'buyer' ? 'lr.seller_id' : 'lr.buyer_id';
        $notHidden = $this->hiddenPlayers->sqlExclude('cp.id');
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('cp');
        // Who asks is named to the seller even when private (asking was their consent)
        $name = $viewerRole === 'buyer' ? "CASE WHEN {$isPrivate} THEN NULL ELSE cp.name END" : 'cp.name';
        // The seller sees what still waits for them first
        $order = $viewerRole === 'seller'
            ? "EXISTS (SELECT 1 FROM listing_request_item w WHERE w.request_id = lr.id AND w.status = 'waiting') DESC, lr.created_at DESC, lr.id DESC"
            : 'lr.created_at DESC, lr.id DESC';
        $limit = self::LIMIT;

        $query = <<<SQL
SELECT
    lr.id AS request_id,
    lr.buyer_id,
    lr.seller_id,
    cp.id AS counterpart_id,
    {$name} AS counterpart_name,
    cp.code AS counterpart_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE cp.avatar END AS counterpart_avatar,
    {$isPrivate} AS counterpart_is_private,
    lr.note,
    lr.created_at,
    lr.expires_at,
    lr.replied_at IS NOT NULL AS replied,
    (
        SELECT json_agg(json_build_object(
            'item_id', item.id,
            'puzzle_id', p.id,
            'puzzle_name', p.name,
            'pieces_count', p.pieces_count,
            'manufacturer_name', m.name,
            'image', CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END,
            'status', item.status,
            'listing_type', item.listing_type,
            'price', item.price,
            'currency', item.currency,
            'condition', item.condition,
            'reserved_by_request', item.reserved_by_request,
            'listing_id', ssli.id,
            'current_price', ssli.price,
            'reserved_for_buyer', COALESCE(ssli.reserved AND ssli.reserved_for_player_id = lr.buyer_id, false),
            'reserved_for_somebody_else', COALESCE(ssli.reserved AND (ssli.reserved_for_player_id IS NULL OR ssli.reserved_for_player_id <> lr.buyer_id), false)
        ) ORDER BY p.name, item.id)
        FROM listing_request_item item
        JOIN puzzle p ON p.id = item.puzzle_id
        LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
        LEFT JOIN sell_swap_list_item ssli ON ssli.id = item.listing_id
        WHERE item.request_id = lr.id
    ) AS items,
    (
        SELECT json_agg(json_build_object(
            'puzzle_id', p.id,
            'puzzle_name', p.name,
            'pieces_count', p.pieces_count,
            'manufacturer_name', m.name,
            'image', CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END,
            'listing_type', offer.listing_type,
            'condition', offer.condition,
            'listing_id', offer.listing_id
        ) ORDER BY p.name, offer.id)
        FROM listing_request_offer offer
        JOIN puzzle p ON p.id = offer.puzzle_id
        LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
        WHERE offer.request_id = lr.id
    ) AS offers
FROM listing_request lr
JOIN player cp ON cp.id = {$counterpartColumn}
WHERE {$condition}{$notHidden}
ORDER BY {$order}
LIMIT {$limit}
SQL;

        /** @var list<array{request_id: string, buyer_id: string, seller_id: string, counterpart_id: string, counterpart_name: null|string, counterpart_code: string, counterpart_avatar: null|string, counterpart_is_private: bool, note: null|string, created_at: string, expires_at: string, replied: bool, items: null|string, offers: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [...$params, 'now' => $this->clock->now()->format('Y-m-d H:i:s')])
            ->fetchAllAssociative();

        return array_map(static function (array $row): ListingRequestOverview {
            /** @var list<array{item_id: string, puzzle_id: string, puzzle_name: string, pieces_count: int, manufacturer_name: null|string, image: null|string, status: string, listing_type: string, price: null|float|int, currency: null|string, condition: string, reserved_by_request: bool, listing_id: null|string, current_price: null|float|int, reserved_for_buyer: bool, reserved_for_somebody_else: bool}> $items */
            $items = $row['items'] !== null ? json_decode($row['items'], true, flags: JSON_THROW_ON_ERROR) : [];
            /** @var list<array{puzzle_id: string, puzzle_name: string, pieces_count: int, manufacturer_name: null|string, image: null|string, listing_type: string, condition: string, listing_id: null|string}> $offers */
            $offers = $row['offers'] !== null ? json_decode($row['offers'], true, flags: JSON_THROW_ON_ERROR) : [];

            return new ListingRequestOverview(
                requestId: $row['request_id'],
                buyerId: $row['buyer_id'],
                sellerId: $row['seller_id'],
                counterpartId: $row['counterpart_id'],
                counterpartName: $row['counterpart_name'],
                counterpartCode: $row['counterpart_code'],
                counterpartAvatar: $row['counterpart_avatar'],
                counterpartIsPrivate: $row['counterpart_is_private'],
                note: $row['note'],
                createdAt: new DateTimeImmutable($row['created_at']),
                expiresAt: new DateTimeImmutable($row['expires_at']),
                replied: $row['replied'],
                items: array_map(static fn (array $item): ListingRequestItemOverview => new ListingRequestItemOverview(
                    itemId: $item['item_id'],
                    puzzleId: $item['puzzle_id'],
                    puzzleName: $item['puzzle_name'],
                    piecesCount: $item['pieces_count'],
                    manufacturerName: $item['manufacturer_name'],
                    image: $item['image'],
                    status: ListingRequestItemStatus::from($item['status']),
                    listingType: ListingType::from($item['listing_type']),
                    price: $item['price'] !== null ? (float) $item['price'] : null,
                    currency: $item['currency'],
                    condition: PuzzleCondition::from($item['condition']),
                    reservedByRequest: $item['reserved_by_request'],
                    listingId: $item['listing_id'],
                    currentPrice: $item['current_price'] !== null ? (float) $item['current_price'] : null,
                    reservedForBuyer: $item['reserved_for_buyer'],
                    reservedForSomebodyElse: $item['reserved_for_somebody_else'],
                ), $items),
                offers: array_map(static fn (array $offer): ListingRequestOfferOverview => new ListingRequestOfferOverview(
                    puzzleId: $offer['puzzle_id'],
                    puzzleName: $offer['puzzle_name'],
                    piecesCount: $offer['pieces_count'],
                    manufacturerName: $offer['manufacturer_name'],
                    image: $offer['image'],
                    listingType: ListingType::from($offer['listing_type']),
                    condition: PuzzleCondition::from($offer['condition']),
                    listingId: $offer['listing_id'],
                ), $offers),
            );
        }, $rows);
    }
}
