<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\MarkMessagesAsRead;
use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkMessagesAsReadHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private Connection $database,
        private MercureNotifier $mercureNotifier,
        private GetConversations $getConversations,
    ) {
    }

    /**
     * @throws ConversationNotFound
     */
    public function __invoke(MarkMessagesAsRead $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

        // Bulk update: set readAt = now() on all messages where sender is NOT the current player and readAt IS NULL
        $this->database->executeStatement(
            'UPDATE chat_message SET read_at = NOW() WHERE conversation_id = :conversationId AND sender_id != :playerId AND read_at IS NULL',
            [
                'conversationId' => $message->conversationId,
                'playerId' => $message->playerId,
            ],
        );

        $unreadCount = $this->getConversations->countUnreadForPlayer($message->playerId);
        $this->mercureNotifier->notifyUnreadCountChanged($message->playerId, $unreadCount);
    }
}
