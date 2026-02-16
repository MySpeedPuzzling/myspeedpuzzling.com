<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Events\ChatMessageSent;
use SpeedPuzzling\Web\Exceptions\ChatMessageNotFound;
use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Repository\ChatMessageRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyOnChatMessageSent
{
    public function __construct(
        private ChatMessageRepository $chatMessageRepository,
        private MercureNotifier $mercureNotifier,
        private GetConversations $getConversations,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ChatMessageNotFound
     */
    public function __invoke(ChatMessageSent $event): void
    {
        $chatMessage = $this->chatMessageRepository->get($event->chatMessageId->toString());
        $conversation = $chatMessage->conversation;

        $isInitiator = $conversation->initiator->id->toString() === $event->senderId;
        $otherParticipant = $isInitiator ? $conversation->recipient : $conversation->initiator;

        try {
            $this->mercureNotifier->notifyNewMessage($chatMessage);

            if ($conversation->status === ConversationStatus::Accepted) {
                $recipientId = $otherParticipant->id->toString();
                $unreadCount = $this->getConversations->countUnreadForPlayer($recipientId);
                $this->mercureNotifier->notifyUnreadCountChanged($recipientId, $unreadCount);
            }

            $this->mercureNotifier->notifyConversationListChanged($otherParticipant->id->toString());
            $this->mercureNotifier->notifyConversationListChanged($event->senderId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for message', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
