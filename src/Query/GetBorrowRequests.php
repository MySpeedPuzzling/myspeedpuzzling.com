<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\AskedBorrow;
use SpeedPuzzling\Web\Results\BorrowRequestItemOverview;
use SpeedPuzzling\Web\Results\BorrowRequestOverview;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;

/**
 * Borrow requests as their two sides see them (docs/features/wishlist-availability.md): "Asked by me" (the viewer
 * asked; the counterpart is the owner) and "Asked of me" (the viewer owns; the counterpart is the asker), one request
 * for its page, and the viewer's open asks of the puzzles on their wishlist. Only ever the viewer's own requests.
 *
 * Whoever the viewer blocks is left out (HiddenPlayers). Who asks is named to the owner even when private - asking was
 * their consent; an owner the asker can no longer see is shown by code. A private counterpart is never shown by their
 * picture.
 */
readonly final class GetBorrowRequests
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
     * @return list<BorrowRequestOverview> newest first
     */
    public function askedBy(string $askerId): array
    {
        return $this->requests('asker', 'br.asker_id = :viewerId', ['viewerId' => $askerId]);
    }

    /**
     * @return list<BorrowRequestOverview> newest first
     */
    public function askedOf(string $ownerId): array
    {
        return $this->requests('owner', 'br.owner_id = :viewerId', ['viewerId' => $ownerId]);
    }

    /**
     * One request for its page - null for anybody but its asker or owner.
     */
    public function forViewer(string $requestId, string $viewerId): null|BorrowRequestOverview
    {
        if (Uuid::isValid($requestId) === false || Uuid::isValid($viewerId) === false) {
            return null;
        }

        $params = ['viewerId' => $viewerId, 'requestId' => $requestId];

        return $this->requests('asker', 'br.id = :requestId AND br.asker_id = :viewerId', $params)[0]
            ?? $this->requests('owner', 'br.id = :requestId AND br.owner_id = :viewerId', $params)[0]
            ?? null;
    }

    /**
     * The viewer's open asks (waiting or yes) of these puzzles - the wishlist cards' "Asked Anna · waiting" / "Anna: yes".
     *
     * @param list<string> $puzzleIds
     * @return array<string, list<AskedBorrow>> by puzzle id
     */
    public function openOfAsker(string $askerId, array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_filter(array_map(strtolower(...), $puzzleIds), static fn (string $id): bool => Uuid::isValid($id))));

        if ($puzzleIds === [] || Uuid::isValid($askerId) === false) {
            return [];
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('owner.id');
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('owner');

        $query = <<<SQL
SELECT
    item.id AS item_id,
    item.request_id,
    item.puzzle_id,
    owner.id AS owner_id,
    CASE WHEN {$isPrivate} THEN NULL ELSE owner.name END AS owner_name,
    owner.code AS owner_code,
    item.status
FROM borrow_request_item item
JOIN player owner ON owner.id = item.owner_id
WHERE item.asker_id = :askerId
    AND item.puzzle_id = ANY(:puzzleIds)
    AND item.status IN ('waiting', 'yes'){$notHidden}
ORDER BY item.status = 'yes' DESC, item.id
SQL;

        /** @var list<array{item_id: string, request_id: string, puzzle_id: string, owner_id: string, owner_name: null|string, owner_code: string, status: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'askerId' => $askerId,
                'puzzleIds' => '{' . implode(',', $puzzleIds) . '}',
            ])
            ->fetchAllAssociative();

        $asked = [];

        foreach ($rows as $row) {
            $asked[$row['puzzle_id']][] = new AskedBorrow(
                itemId: $row['item_id'],
                requestId: $row['request_id'],
                puzzleId: $row['puzzle_id'],
                ownerId: $row['owner_id'],
                ownerName: $row['owner_name'],
                ownerCode: $row['owner_code'],
                status: BorrowRequestItemStatus::from($row['status']),
            );
        }

        return $asked;
    }

    /**
     * @param 'asker'|'owner' $viewerRole
     * @param array<string, string> $params
     * @return list<BorrowRequestOverview>
     */
    private function requests(string $viewerRole, string $condition, array $params): array
    {
        if (Uuid::isValid($params['viewerId']) === false) {
            return [];
        }

        $counterpartColumn = $viewerRole === 'asker' ? 'br.owner_id' : 'br.asker_id';
        $notHidden = $this->hiddenPlayers->sqlExclude('cp.id');
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('cp');
        // Who asks is named to the owner even when private (asking was their consent)
        $name = $viewerRole === 'asker' ? "CASE WHEN {$isPrivate} THEN NULL ELSE cp.name END" : 'cp.name';
        $limit = self::LIMIT;

        $query = <<<SQL
SELECT
    br.id AS request_id,
    br.asker_id,
    br.owner_id,
    cp.id AS counterpart_id,
    {$name} AS counterpart_name,
    cp.code AS counterpart_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE cp.avatar END AS counterpart_avatar,
    {$isPrivate} AS counterpart_is_private,
    br.note,
    br.created_at,
    br.expires_at,
    br.replied_at IS NOT NULL AS replied,
    (
        SELECT json_agg(json_build_object(
            'item_id', item.id,
            'puzzle_id', p.id,
            'puzzle_name', p.name,
            'pieces_count', p.pieces_count,
            'manufacturer_name', m.name,
            'image', CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END,
            'status', item.status
        ) ORDER BY p.name, item.id)
        FROM borrow_request_item item
        JOIN puzzle p ON p.id = item.puzzle_id
        LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
        WHERE item.request_id = br.id
    ) AS items
FROM borrow_request br
JOIN player cp ON cp.id = {$counterpartColumn}
WHERE {$condition}{$notHidden}
ORDER BY br.created_at DESC, br.id DESC
LIMIT {$limit}
SQL;

        /** @var list<array{request_id: string, asker_id: string, owner_id: string, counterpart_id: string, counterpart_name: null|string, counterpart_code: string, counterpart_avatar: null|string, counterpart_is_private: bool, note: null|string, created_at: string, expires_at: string, replied: bool, items: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [...$params, 'now' => $this->clock->now()->format('Y-m-d H:i:s')])
            ->fetchAllAssociative();

        return array_map(static function (array $row): BorrowRequestOverview {
            /** @var list<array{item_id: string, puzzle_id: string, puzzle_name: string, pieces_count: int, manufacturer_name: null|string, image: null|string, status: string}> $items */
            $items = $row['items'] !== null ? json_decode($row['items'], true, flags: JSON_THROW_ON_ERROR) : [];

            return new BorrowRequestOverview(
                requestId: $row['request_id'],
                askerId: $row['asker_id'],
                ownerId: $row['owner_id'],
                counterpartId: $row['counterpart_id'],
                counterpartName: $row['counterpart_name'],
                counterpartCode: $row['counterpart_code'],
                counterpartAvatar: $row['counterpart_avatar'],
                counterpartIsPrivate: $row['counterpart_is_private'],
                note: $row['note'],
                createdAt: new DateTimeImmutable($row['created_at']),
                expiresAt: new DateTimeImmutable($row['expires_at']),
                replied: $row['replied'],
                items: array_map(static fn (array $item): BorrowRequestItemOverview => new BorrowRequestItemOverview(
                    itemId: $item['item_id'],
                    puzzleId: $item['puzzle_id'],
                    puzzleName: $item['puzzle_name'],
                    piecesCount: $item['pieces_count'],
                    manufacturerName: $item['manufacturer_name'],
                    image: $item['image'],
                    status: BorrowRequestItemStatus::from($item['status']),
                ), $items),
            );
        }, $rows);
    }
}
