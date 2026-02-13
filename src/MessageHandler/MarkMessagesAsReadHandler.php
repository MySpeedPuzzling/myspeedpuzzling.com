<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\MarkMessagesAsRead;
use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkMessagesAsReadHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private Connection $database,
        private MercureNotifier $mercureNotifier,
        private GetConversations $getConversations,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ConversationNotFound
     */
    public function __invoke(MarkMessagesAsRead $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

        // Bulk update: set readAt = now() on all messages where sender is NOT the current player and readAt IS NULL
        $affectedRows = $this->database->executeStatement(
            'UPDATE chat_message SET read_at = NOW() WHERE conversation_id = :conversationId AND sender_id != :playerId AND read_at IS NULL',
            [
                'conversationId' => $message->conversationId,
                'playerId' => $message->playerId,
            ],
        );

        try {
            $unreadCount = $this->getConversations->countUnreadForPlayer($message->playerId);
            $this->mercureNotifier->notifyUnreadCountChanged($message->playerId, $unreadCount);

            if ($affectedRows > 0) {
                $this->mercureNotifier->notifyMessagesRead($message->conversationId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for unread count', [
                'conversationId' => $message->conversationId,
                'exception' => $e,
            ]);
        }
    }
}
