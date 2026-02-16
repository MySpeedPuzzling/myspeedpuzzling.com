<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MessageView;

readonly final class GetConversationLog
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<MessageView>
     */
    public function fullLog(string $conversationId): array
    {
        $query = <<<SQL
SELECT
    cm.id AS message_id,
    cm.sender_id,
    p.name AS sender_name,
    p.avatar AS sender_avatar,
    cm.content,
    cm.sent_at,
    cm.read_at,
    cm.system_message_type
FROM chat_message cm
LEFT JOIN player p ON cm.sender_id = p.id
WHERE cm.conversation_id = :conversationId
ORDER BY cm.sent_at ASC
SQL;

        $data = $this->database
            ->executeQuery($query, ['conversationId' => $conversationId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): MessageView {
            /** @var array{
             *     message_id: string,
             *     sender_id: null|string,
             *     sender_name: null|string,
             *     sender_avatar: null|string,
             *     content: string,
             *     sent_at: string,
             *     read_at: null|string,
             *     system_message_type: null|string,
             * } $row
             */

            return new MessageView(
                messageId: $row['message_id'],
                senderId: $row['sender_id'],
                senderName: $row['sender_name'],
                senderAvatar: $row['sender_avatar'],
                content: $row['content'],
                sentAt: new DateTimeImmutable($row['sent_at']),
                readAt: $row['read_at'] !== null ? new DateTimeImmutable($row['read_at']) : null,
                isOwnMessage: false,
                isSystemMessage: $row['system_message_type'] !== null,
                systemTranslationKey: $row['system_message_type'],
            );
        }, $data);
    }
}
