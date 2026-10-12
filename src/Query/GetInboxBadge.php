<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\ConversationStatus;
use SpeedPuzzling\Web\Value\InboxBadgeParts;
use SpeedPuzzling\Web\Value\NotificationType;

/**
 * The Inbox badge on every signed-in page (docs/features/navigation INB-02, README §4.4 / D3): one statement with
 * three scalar subqueries, replacing the two unread counts the header ran before.
 *
 * - for_you: unread notifications except SubscribedPlayerAddedTime (favorites' times, D4) and NewConversationRequest
 *   (the request counts under Messages). The two types are inlined as literals, never bound, so the planner matches
 *   the partial index custom_notification_unread_for_you and answers it with an index-only scan (COUNT(*), not
 *   COUNT(id)) whenever nobody is hidden.
 * - unread_conversations: accepted conversations with an unread message not sent by the player (system messages
 *   count), unless the player blocks the other side.
 * - pending_requests: message requests waiting for the player, unless the player blocks the initiator.
 * - Both count only what the Messages lists show (GetConversations::forPlayer() / pendingRequestsForPlayer() join both
 *   players): a deleted player's side is NULL (DeletePlayerHandler::anonymizeConversations()), and a request or a
 *   conversation nobody can open any more must not be counted forever (INB-02 review F2).
 *
 * The hidden players are passed explicitly instead of read from the request's viewer (HiddenPlayers): a realtime push
 * runs in the sender's request or in an async consumer, where the viewer is somebody else or nobody.
 */
readonly final class GetInboxBadge
{
    public const array NOT_FOR_YOU = [
        NotificationType::SubscribedPlayerAddedTime,
        NotificationType::NewConversationRequest,
    ];

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $hiddenIds the players $playerId must not be shown (their blocks, user_block)
     */
    public function forPlayer(string $playerId, array $hiddenIds): InboxBadgeParts
    {
        $notForYou = implode(', ', array_map(
            static fn (NotificationType $type): string => "'{$type->value}'",
            self::NOT_FOR_YOU,
        ));
        $notHidden = HiddenPlayers::sqlNotificationNotHiddenFor($hiddenIds, $playerId);

        $query = <<<SQL
SELECT
    (
        SELECT COUNT(*)
        FROM notification
        WHERE notification.player_id = :playerId
            AND notification.read_at IS NULL
            AND notification.type NOT IN ({$notForYou})
            {$notHidden}
    ) AS for_you,
    (
        SELECT COUNT(DISTINCT c.id)
        FROM conversation c
        JOIN chat_message cm ON cm.conversation_id = c.id
        WHERE (c.initiator_id = :playerId OR c.recipient_id = :playerId)
            AND c.status = :accepted
            AND c.initiator_id IS NOT NULL
            AND c.recipient_id IS NOT NULL
            AND cm.read_at IS NULL
            AND (
                (cm.sender_id IS NOT NULL AND cm.sender_id != :playerId)
                OR cm.sender_id IS NULL
            )
            AND NOT EXISTS (
                SELECT 1 FROM user_block ub
                WHERE ub.blocker_id = :playerId
                AND ub.blocked_id = CASE WHEN c.initiator_id = :playerId THEN c.recipient_id ELSE c.initiator_id END
            )
    ) AS unread_conversations,
    (
        SELECT COUNT(*)
        FROM conversation c
        WHERE c.recipient_id = :playerId
            AND c.status = :pending
            AND c.initiator_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM user_block ub
                WHERE ub.blocker_id = :playerId
                AND ub.blocked_id = c.initiator_id
            )
    ) AS pending_requests
SQL;

        /** @var false|array{for_you: int|string, unread_conversations: int|string, pending_requests: int|string} $row */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'accepted' => ConversationStatus::Accepted->value,
                'pending' => ConversationStatus::Pending->value,
            ])
            ->fetchAssociative();

        if ($row === false) {
            return InboxBadgeParts::none();
        }

        return new InboxBadgeParts(
            forYou: (int) $row['for_you'],
            unreadConversations: (int) $row['unread_conversations'],
            pendingRequests: (int) $row['pending_requests'],
        );
    }
}
