<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Exceptions\ConversationRequestAlreadyPending;
use SpeedPuzzling\Web\Exceptions\DirectMessagesDisabled;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\UserIsBlocked;
use SpeedPuzzling\Web\Message\SendMessage;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Repository\ChatMessageRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class StartConversationHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ConversationRepository $conversationRepository,
        private ChatMessageRepository $chatMessageRepository,
        private UserBlockRepository $userBlockRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private MercureNotifier $mercureNotifier,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws UserIsBlocked
     * @throws DirectMessagesDisabled
     * @throws ConversationRequestAlreadyPending
     */
    public function __invoke(StartConversation $message): void
    {
        $initiator = $this->playerRepository->get($message->initiatorId);
        $recipient = $this->playerRepository->get($message->recipientId);

        // Check if initiator is blocked by recipient
        $block = $this->userBlockRepository->findByBlockerAndBlocked($recipient, $initiator);
        if ($block !== null) {
            throw new UserIsBlocked();
        }

        $sellSwapListItem = null;
        $puzzle = null;
        $isMarketplace = $message->sellSwapListItemId !== null;

        if ($isMarketplace && $message->sellSwapListItemId !== null) {
            $sellSwapListItem = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);
            $puzzle = $sellSwapListItem->puzzle;
        } elseif ($message->puzzleId !== null) {
            // Puzzle provided without listing (shouldn't normally happen, but handle gracefully)
        }

        // For general conversations, check if recipient allows direct messages
        if (!$isMarketplace && !$recipient->allowDirectMessages) {
            throw new DirectMessagesDisabled();
        }

        // Check if accepted conversation already exists between these two users (in either direction)
        $existingAccepted = $this->conversationRepository->findAcceptedBetween($initiator, $recipient);

        if ($existingAccepted !== null) {
            if ($isMarketplace) {
                // Auto-accept new marketplace conversations when already accepted
                $now = new DateTimeImmutable();
                $conversation = new Conversation(
                    id: Uuid::uuid7(),
                    initiator: $initiator,
                    recipient: $recipient,
                    status: ConversationStatus::Accepted,
                    createdAt: $now,
                    sellSwapListItem: $sellSwapListItem,
                    puzzle: $puzzle,
                    respondedAt: $now,
                    lastMessageAt: $now,
                );

                $this->conversationRepository->save($conversation);

                $chatMessage = new ChatMessage(
                    id: Uuid::uuid7(),
                    conversation: $conversation,
                    sender: $initiator,
                    content: $message->initialMessage,
                    sentAt: $now,
                );

                $this->chatMessageRepository->save($chatMessage);

                $this->mercureNotifier->notifyNewMessage($chatMessage);
                $this->mercureNotifier->notifyNewConversationRequest($conversation);

                return;
            }

            // For general conversations, reuse existing — just send the message
            $this->messageBus->dispatch(new SendMessage(
                conversationId: $existingAccepted->id->toString(),
                senderId: $message->initiatorId,
                content: $message->initialMessage,
            ));

            return;
        }

        // Check if a pending request already exists (from initiator to recipient)
        $existingPending = $this->conversationRepository->findPendingBetween($initiator, $recipient);
        if ($existingPending !== null) {
            throw new ConversationRequestAlreadyPending();
        }

        // Also check reverse direction for pending
        $existingPendingReverse = $this->conversationRepository->findPendingBetween($recipient, $initiator);
        if ($existingPendingReverse !== null) {
            throw new ConversationRequestAlreadyPending();
        }

        $now = new DateTimeImmutable();

        $conversation = new Conversation(
            id: Uuid::uuid7(),
            initiator: $initiator,
            recipient: $recipient,
            status: ConversationStatus::Pending,
            createdAt: $now,
            sellSwapListItem: $sellSwapListItem,
            puzzle: $puzzle,
            lastMessageAt: $now,
        );

        $this->conversationRepository->save($conversation);

        $chatMessage = new ChatMessage(
            id: Uuid::uuid7(),
            conversation: $conversation,
            sender: $initiator,
            content: $message->initialMessage,
            sentAt: $now,
        );

        $this->chatMessageRepository->save($chatMessage);

        $this->mercureNotifier->notifyNewConversationRequest($conversation);
    }
}
