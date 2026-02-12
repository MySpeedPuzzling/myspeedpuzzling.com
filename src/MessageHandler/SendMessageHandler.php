<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\UserIsBlocked;
use SpeedPuzzling\Web\Message\SendMessage;
use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Repository\ChatMessageRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SendMessageHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private ChatMessageRepository $chatMessageRepository,
        private PlayerRepository $playerRepository,
        private UserBlockRepository $userBlockRepository,
        private MercureNotifier $mercureNotifier,
        private GetConversations $getConversations,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ConversationNotFound
     * @throws PlayerNotFound
     * @throws UserIsBlocked
     * @throws MessagingMuted
     */
    public function __invoke(SendMessage $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

        if ($conversation->status !== ConversationStatus::Accepted) {
            throw new ConversationNotFound();
        }

        $sender = $this->playerRepository->get($message->senderId);

        if ($sender->isMessagingMuted()) {
            throw new MessagingMuted();
        }

        // Verify sender is a participant
        $isInitiator = $conversation->initiator->id->toString() === $message->senderId;
        $isRecipient = $conversation->recipient->id->toString() === $message->senderId;

        if (!$isInitiator && !$isRecipient) {
            throw new ConversationNotFound();
        }

        // Determine the other participant
        $otherParticipant = $isInitiator ? $conversation->recipient : $conversation->initiator;

        // Check if sender is blocked by the other participant
        $block = $this->userBlockRepository->findByBlockerAndBlocked($otherParticipant, $sender);
        if ($block !== null) {
            throw new UserIsBlocked();
        }

        $now = new DateTimeImmutable();

        $chatMessage = new ChatMessage(
            id: Uuid::uuid7(),
            conversation: $conversation,
            sender: $sender,
            content: mb_substr($message->content, 0, 2000),
            sentAt: $now,
        );

        $this->chatMessageRepository->save($chatMessage);
        $conversation->updateLastMessageAt($now);

        try {
            $this->mercureNotifier->notifyNewMessage($chatMessage);

            // Notify recipient about unread count change
            $recipientId = $otherParticipant->id->toString();
            $unreadCount = $this->getConversations->countUnreadForPlayer($recipientId);
            $this->mercureNotifier->notifyUnreadCountChanged($recipientId, $unreadCount);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for message', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
