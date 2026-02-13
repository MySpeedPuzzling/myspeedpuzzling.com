<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PendingRequestNotification;
use SpeedPuzzling\Web\Results\UnreadMessageNotification;
use SpeedPuzzling\Web\Results\UnreadMessageSummary;

readonly final class GetPlayersWithUnreadMessages
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return UnreadMessageNotification[]
     */
    public function findPlayersToNotify(int $hoursThreshold = 12): array
    {
        $query = <<<SQL
SELECT
    p.id as player_id,
    p.email as player_email,
    p.name as player_name,
    p.locale as player_locale,
    MIN(cm.sent_at) as oldest_unread_at,
    COUNT(cm.id) as unread_count
FROM player p
JOIN conversation c ON (c.initiator_id = p.id OR c.recipient_id = p.id)
JOIN chat_message cm ON cm.conversation_id = c.id
WHERE p.email IS NOT NULL
  AND p.email_notifications_enabled = true
  AND c.status = 'accepted'
  AND cm.sender_id != p.id
  AND cm.read_at IS NULL
  AND cm.sent_at < NOW() - CAST(:hours AS INTERVAL)
GROUP BY p.id, p.email, p.name, p.locale
HAVING MIN(cm.sent_at) > COALESCE(
    (SELECT MAX(mnl.oldest_unread_message_at)
     FROM message_notification_log mnl
     WHERE mnl.player_id = p.id),
    '1970-01-01'::timestamptz
)
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'hours' => $hoursThreshold . ' hours',
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): UnreadMessageNotification {
            /** @var array{
             *     player_id: string,
             *     player_email: string,
             *     player_name: null|string,
             *     player_locale: null|string,
             *     oldest_unread_at: string,
             *     unread_count: int|string,
             * } $row
             */

            return new UnreadMessageNotification(
                playerId: $row['player_id'],
                playerEmail: $row['player_email'],
                playerName: $row['player_name'],
                playerLocale: $row['player_locale'],
                oldestUnreadAt: new DateTimeImmutable($row['oldest_unread_at']),
                totalUnreadCount: (int) $row['unread_count'],
            );
        }, $rows);
    }

    /**
     * @return PendingRequestNotification[]
     */
    public function findPlayersWithPendingRequestsToNotify(int $hoursThreshold = 12): array
    {
        $query = <<<SQL
SELECT
    p.id as player_id,
    p.email as player_email,
    p.name as player_name,
    p.locale as player_locale,
    MIN(c.created_at) as oldest_pending_at,
    COUNT(c.id) as pending_count
FROM player p
JOIN conversation c ON c.recipient_id = p.id
WHERE p.email IS NOT NULL
  AND p.email_notifications_enabled = true
  AND c.status = 'pending'
  AND c.created_at < NOW() - CAST(:hours AS INTERVAL)
GROUP BY p.id, p.email, p.name, p.locale
HAVING MIN(c.created_at) > COALESCE(
    (SELECT MAX(rnl.oldest_pending_request_at)
     FROM request_notification_log rnl
     WHERE rnl.player_id = p.id),
    '1970-01-01'::timestamptz
)
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'hours' => $hoursThreshold . ' hours',
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PendingRequestNotification {
            /** @var array{
             *     player_id: string,
             *     player_email: string,
             *     player_name: null|string,
             *     player_locale: null|string,
             *     oldest_pending_at: string,
             *     pending_count: int|string,
             * } $row
             */

            return new PendingRequestNotification(
                playerId: $row['player_id'],
                playerEmail: $row['player_email'],
                playerName: $row['player_name'],
                playerLocale: $row['player_locale'],
                oldestPendingAt: new DateTimeImmutable($row['oldest_pending_at']),
                pendingCount: (int) $row['pending_count'],
            );
        }, $rows);
    }

    /**
     * @return UnreadMessageSummary[]
     */
    public function getUnreadSummaryForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    sender.name as sender_name,
    sender.code as sender_code,
    COUNT(cm.id) as unread_count,
    pz.name as puzzle_name,
    c.id as conversation_id
FROM chat_message cm
JOIN conversation c ON c.id = cm.conversation_id
JOIN player sender ON sender.id = cm.sender_id
LEFT JOIN puzzle pz ON pz.id = c.puzzle_id
WHERE (c.initiator_id = :playerId OR c.recipient_id = :playerId)
  AND c.status = 'accepted'
  AND cm.sender_id != :playerId
  AND cm.read_at IS NULL
GROUP BY sender.name, sender.code, pz.name, c.id
ORDER BY MIN(cm.sent_at) ASC
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): UnreadMessageSummary {
            /** @var array{
             *     sender_name: null|string,
             *     sender_code: string,
             *     unread_count: int|string,
             *     puzzle_name: null|string,
             *     conversation_id: string,
             * } $row
             */

            return new UnreadMessageSummary(
                senderName: $row['sender_name'] ?? $row['sender_code'],
                senderCode: $row['sender_code'],
                unreadCount: (int) $row['unread_count'],
                puzzleName: $row['puzzle_name'],
                conversationId: $row['conversation_id'],
            );
        }, $rows);
    }
}
