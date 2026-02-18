<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendMessage;
use SpeedPuzzling\Web\Repository\ChatMessageRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SendMessageHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private ChatMessageRepository $chatMessageRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws ConversationNotFound
     * @throws PlayerNotFound
     * @throws MessagingMuted
     */
    public function __invoke(SendMessage $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

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

        // Check conversation status and permissions
        match ($conversation->status) {
            ConversationStatus::Accepted => null, // Anyone can send
            ConversationStatus::Pending, ConversationStatus::Ignored => $isInitiator ? null : throw new ConversationNotFound(),
        };

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
    }
}
