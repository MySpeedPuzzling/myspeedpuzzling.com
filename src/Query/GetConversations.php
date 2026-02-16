<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ConversationOverview;
use SpeedPuzzling\Web\Value\ConversationStatus;

readonly final class GetConversations
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<ConversationOverview>
     */
    public function forPlayer(string $playerId, null|ConversationStatus $status = null): array
    {
        $statusFilter = '';
        $params = ['playerId' => $playerId];

        if ($status !== null) {
            $statusFilter = 'AND c.status = :status';
            $params['status'] = $status->value;
        } else {
            // Show accepted conversations + pending/ignored conversations where the player is the initiator
            $statusFilter = 'AND (c.status = :statusAccepted OR (c.status IN (:statusPending, :statusIgnored) AND c.initiator_id = :playerId))';
            $params['statusAccepted'] = ConversationStatus::Accepted->value;
            $params['statusPending'] = ConversationStatus::Pending->value;
            $params['statusIgnored'] = ConversationStatus::Ignored->value;
        }

        $query = <<<SQL
SELECT
    c.id AS conversation_id,
    c.status,
    c.last_message_at,
    c.sell_swap_list_item_id,
    -- Other participant info
    CASE WHEN c.initiator_id = :playerId THEN c.recipient_id ELSE c.initiator_id END AS other_player_id,
    CASE WHEN c.initiator_id = :playerId THEN rp.name ELSE ip.name END AS other_player_name,
    CASE WHEN c.initiator_id = :playerId THEN rp.code ELSE ip.code END AS other_player_code,
    CASE WHEN c.initiator_id = :playerId THEN rp.avatar ELSE ip.avatar END AS other_player_avatar,
    CASE WHEN c.initiator_id = :playerId THEN rp.country ELSE ip.country END AS other_player_country,
    -- Last message preview
    (
        SELECT LEFT(cm.content, 80)
        FROM chat_message cm
        WHERE cm.conversation_id = c.id
        ORDER BY cm.sent_at DESC
        LIMIT 1
    ) AS last_message_preview,
    -- Was last message sent by current player?
    (
        SELECT cm.sender_id = :playerId
        FROM chat_message cm
        WHERE cm.conversation_id = c.id
        ORDER BY cm.sent_at DESC
        LIMIT 1
    ) AS last_message_sent_by_me,
    -- Unread count for this player
    (
        SELECT COUNT(*)
        FROM chat_message cm
        WHERE cm.conversation_id = c.id
            AND (cm.sender_id IS NULL OR cm.sender_id != :playerId)
            AND cm.read_at IS NULL
    ) AS unread_count,
    -- Puzzle context
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.image AS puzzle_image,
    sli.listing_type AS listing_type,
    sli.price AS listing_price
FROM conversation c
JOIN player ip ON c.initiator_id = ip.id
JOIN player rp ON c.recipient_id = rp.id
LEFT JOIN puzzle p ON c.puzzle_id = p.id
LEFT JOIN sell_swap_list_item sli ON c.sell_swap_list_item_id = sli.id
WHERE (c.initiator_id = :playerId OR c.recipient_id = :playerId)
    {$statusFilter}
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE ub.blocker_id = :playerId
        AND ub.blocked_id = CASE WHEN c.initiator_id = :playerId THEN c.recipient_id ELSE c.initiator_id END
    )
ORDER BY c.last_message_at DESC NULLS LAST
SQL;

        $data = $this->database
            ->executeQuery($query, $params)
            ->fetchAllAssociative();

        return array_map(static function (array $row): ConversationOverview {
            /** @var array{
             *     conversation_id: string,
             *     status: string,
             *     last_message_at: null|string,
             *     sell_swap_list_item_id: null|string,
             *     other_player_id: string,
             *     other_player_name: null|string,
             *     other_player_code: string,
             *     other_player_avatar: null|string,
             *     other_player_country: null|string,
             *     last_message_preview: null|string,
             *     last_message_sent_by_me: null|bool,
             *     unread_count: int|string,
             *     puzzle_id: null|string,
             *     puzzle_name: null|string,
             *     puzzle_image: null|string,
             *     listing_type: null|string,
             *     listing_price: null|string,
             * } $row
             */

            return new ConversationOverview(
                conversationId: $row['conversation_id'],
                otherPlayerName: $row['other_player_name'] ?? $row['other_player_code'],
                otherPlayerCode: $row['other_player_code'],
                otherPlayerId: $row['other_player_id'],
                otherPlayerAvatar: $row['other_player_avatar'],
                otherPlayerCountry: $row['other_player_country'],
                lastMessagePreview: $row['last_message_preview'],
                lastMessageAt: $row['last_message_at'] !== null ? new DateTimeImmutable($row['last_message_at']) : null,
                unreadCount: (int) $row['unread_count'],
                status: ConversationStatus::from($row['status']),
                puzzleName: $row['puzzle_name'],
                puzzleId: $row['puzzle_id'],
                sellSwapListItemId: $row['sell_swap_list_item_id'],
                puzzleImage: $row['puzzle_image'],
                listingType: $row['listing_type'],
                listingPrice: $row['listing_price'] !== null ? (float) $row['listing_price'] : null,
                lastMessageSentByMe: (bool) ($row['last_message_sent_by_me'] ?? false),
            );
        }, $data);
    }

    /**
     * @return array<ConversationOverview>
     */
    public function pendingRequestsForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    c.id AS conversation_id,
    c.status,
    c.last_message_at,
    c.created_at,
    c.sell_swap_list_item_id,
    c.initiator_id AS other_player_id,
    ip.name AS other_player_name,
    ip.code AS other_player_code,
    ip.avatar AS other_player_avatar,
    ip.country AS other_player_country,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.image AS puzzle_image,
    sli.listing_type AS listing_type,
    sli.price AS listing_price
FROM conversation c
JOIN player ip ON c.initiator_id = ip.id
LEFT JOIN puzzle p ON c.puzzle_id = p.id
LEFT JOIN sell_swap_list_item sli ON c.sell_swap_list_item_id = sli.id
WHERE c.recipient_id = :playerId
    AND c.status = :status
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE ub.blocker_id = :playerId
        AND ub.blocked_id = c.initiator_id
    )
ORDER BY c.created_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'status' => ConversationStatus::Pending->value,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): ConversationOverview {
            /** @var array{
             *     conversation_id: string,
             *     status: string,
             *     last_message_at: null|string,
             *     created_at: string,
             *     sell_swap_list_item_id: null|string,
             *     other_player_id: string,
             *     other_player_name: null|string,
             *     other_player_code: string,
             *     other_player_avatar: null|string,
             *     other_player_country: null|string,
             *     puzzle_id: null|string,
             *     puzzle_name: null|string,
             *     puzzle_image: null|string,
             *     listing_type: null|string,
             *     listing_price: null|string,
             * } $row
             */

            return new ConversationOverview(
                conversationId: $row['conversation_id'],
                otherPlayerName: $row['other_player_name'] ?? $row['other_player_code'],
                otherPlayerCode: $row['other_player_code'],
                otherPlayerId: $row['other_player_id'],
                otherPlayerAvatar: $row['other_player_avatar'],
                otherPlayerCountry: $row['other_player_country'],
                lastMessagePreview: null,
                lastMessageAt: $row['last_message_at'] !== null ? new DateTimeImmutable($row['last_message_at']) : new DateTimeImmutable($row['created_at']),
                unreadCount: 0,
                status: ConversationStatus::from($row['status']),
                puzzleName: $row['puzzle_name'],
                puzzleId: $row['puzzle_id'],
                sellSwapListItemId: $row['sell_swap_list_item_id'],
                puzzleImage: $row['puzzle_image'],
                listingType: $row['listing_type'],
                listingPrice: $row['listing_price'] !== null ? (float) $row['listing_price'] : null,
            );
        }, $data);
    }

    /**
     * @return array<ConversationOverview>
     */
    public function ignoredForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    c.id AS conversation_id,
    c.status,
    c.last_message_at,
    c.created_at,
    c.sell_swap_list_item_id,
    c.initiator_id AS other_player_id,
    ip.name AS other_player_name,
    ip.code AS other_player_code,
    ip.avatar AS other_player_avatar,
    ip.country AS other_player_country,
    (
        SELECT LEFT(cm.content, 80)
        FROM chat_message cm
        WHERE cm.conversation_id = c.id
        ORDER BY cm.sent_at DESC
        LIMIT 1
    ) AS last_message_preview,
    (
        SELECT cm.sender_id = :playerId
        FROM chat_message cm
        WHERE cm.conversation_id = c.id
        ORDER BY cm.sent_at DESC
        LIMIT 1
    ) AS last_message_sent_by_me,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.image AS puzzle_image,
    sli.listing_type AS listing_type,
    sli.price AS listing_price
FROM conversation c
JOIN player ip ON c.initiator_id = ip.id
LEFT JOIN puzzle p ON c.puzzle_id = p.id
LEFT JOIN sell_swap_list_item sli ON c.sell_swap_list_item_id = sli.id
WHERE c.recipient_id = :playerId
    AND c.status = :status
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE ub.blocker_id = :playerId
        AND ub.blocked_id = c.initiator_id
    )
ORDER BY c.last_message_at DESC NULLS LAST, c.created_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'status' => ConversationStatus::Ignored->value,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): ConversationOverview {
            /** @var array{
             *     conversation_id: string,
             *     status: string,
             *     last_message_at: null|string,
             *     created_at: string,
             *     sell_swap_list_item_id: null|string,
             *     other_player_id: string,
             *     other_player_name: null|string,
             *     other_player_code: string,
             *     other_player_avatar: null|string,
             *     other_player_country: null|string,
             *     last_message_preview: null|string,
             *     last_message_sent_by_me: null|bool,
             *     puzzle_id: null|string,
             *     puzzle_name: null|string,
             *     puzzle_image: null|string,
             *     listing_type: null|string,
             *     listing_price: null|string,
             * } $row
             */

            return new ConversationOverview(
                conversationId: $row['conversation_id'],
                otherPlayerName: $row['other_player_name'] ?? $row['other_player_code'],
                otherPlayerCode: $row['other_player_code'],
                otherPlayerId: $row['other_player_id'],
                otherPlayerAvatar: $row['other_player_avatar'],
                otherPlayerCountry: $row['other_player_country'],
                lastMessagePreview: $row['last_message_preview'],
                lastMessageAt: $row['last_message_at'] !== null ? new DateTimeImmutable($row['last_message_at']) : new DateTimeImmutable($row['created_at']),
                unreadCount: 0,
                status: ConversationStatus::from($row['status']),
                puzzleName: $row['puzzle_name'],
                puzzleId: $row['puzzle_id'],
                sellSwapListItemId: $row['sell_swap_list_item_id'],
                puzzleImage: $row['puzzle_image'],
                listingType: $row['listing_type'],
                listingPrice: $row['listing_price'] !== null ? (float) $row['listing_price'] : null,
                lastMessageSentByMe: (bool) ($row['last_message_sent_by_me'] ?? false),
            );
        }, $data);
    }

    public function countUnreadForPlayer(string $playerId): int
    {
        $query = <<<SQL
SELECT COUNT(DISTINCT c.id)
FROM conversation c
JOIN chat_message cm ON cm.conversation_id = c.id
WHERE (c.initiator_id = :playerId OR c.recipient_id = :playerId)
    AND c.status = :status
    AND (cm.sender_id IS NULL OR cm.sender_id != :playerId)
    AND cm.read_at IS NULL
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE ub.blocker_id = :playerId
        AND ub.blocked_id = CASE WHEN c.initiator_id = :playerId THEN c.recipient_id ELSE c.initiator_id END
    )
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'status' => ConversationStatus::Accepted->value,
            ])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }
}
