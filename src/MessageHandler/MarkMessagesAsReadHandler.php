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

        $initiatorId = $conversation->initiator->id->toString();
        $recipientId = $conversation->recipient->id->toString();

        // Verify the player is a participant
        if ($message->playerId !== $initiatorId && $message->playerId !== $recipientId) {
            $this->logger->warning('Non-participant attempted to mark messages as read', [
                'conversationId' => $message->conversationId,
                'playerId' => $message->playerId,
            ]);

            return;
        }

        // The "other" participant is the one whose messages we're marking as read
        $otherPlayerId = $message->playerId === $initiatorId ? $recipientId : $initiatorId;

        // Bulk update: set readAt = now() on all messages where sender is NOT the current player and readAt IS NULL
        // Note: (sender_id IS NULL OR sender_id != :playerId) handles system messages which have NULL sender_id
        $affectedRows = $this->database->executeStatement(
            'UPDATE chat_message SET read_at = NOW() WHERE conversation_id = :conversationId AND (sender_id IS NULL OR sender_id != :playerId) AND read_at IS NULL',
            [
                'conversationId' => $message->conversationId,
                'playerId' => $message->playerId,
            ],
        );

        try {
            $unreadCount = $this->getConversations->countUnreadForPlayer($message->playerId);
            $this->mercureNotifier->notifyUnreadCountChanged($message->playerId, $unreadCount);

            if ($affectedRows > 0) {
                $this->mercureNotifier->notifyMessagesRead($message->conversationId, $otherPlayerId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for unread count', [
                'conversationId' => $message->conversationId,
                'exception' => $e,
            ]);
        }
    }
}
