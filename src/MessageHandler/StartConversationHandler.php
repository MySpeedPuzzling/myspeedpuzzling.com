<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\BorrowRequestNotFound;
use SpeedPuzzling\Web\Exceptions\ConversationRequestAlreadyPending;
use SpeedPuzzling\Web\Exceptions\DirectMessagesDisabled;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendMessage;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Repository\ChatMessageRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use SpeedPuzzling\Web\Value\NotificationType;
use Psr\Log\LoggerInterface;
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
        private NotificationRepository $notificationRepository,
        private PartnerRequestRepository $partnerRequestRepository,
        private BorrowRequestRepository $borrowRequestRepository,
        private MercureNotifier $mercureNotifier,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws DirectMessagesDisabled
     * @throws ConversationRequestAlreadyPending
     * @throws MessagingMuted
     * @throws PartnerRequestNotFound the request is not the recipient's to the initiator's listing
     * @throws BorrowRequestNotFound the borrow request is not the recipient's to the initiator
     */
    public function __invoke(StartConversation $message): void
    {
        $initiator = $this->playerRepository->get($message->initiatorId);

        if ($initiator->isMessagingMuted($this->clock->now())) {
            throw new MessagingMuted();
        }

        $recipient = $this->playerRepository->get($message->recipientId);

        // Check if initiator is blocked by recipient - silently skip notifications
        $isBlocked = $this->userBlockRepository->findByBlockerAndBlocked($recipient, $initiator) !== null;

        $sellSwapListItem = null;
        $puzzle = null;
        $isMarketplace = $message->sellSwapListItemId !== null;

        if ($isMarketplace) {
            $sellSwapListItem = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);
            $puzzle = $sellSwapListItem->puzzle;
        } elseif ($message->puzzleId !== null) {
            // Puzzle provided without listing (shouldn't normally happen, but handle gracefully)
        }

        // A reply to a partner finder or borrow request: only to whoever asked - never a way around
        // "direct messages off" for anybody else
        $isRequestReply = false;

        if ($message->partnerRequestId !== null) {
            $partnerRequest = $this->partnerRequestRepository->get($message->partnerRequestId);

            if (
                $partnerRequest->listing->player->id->equals($initiator->id) === false
                || $partnerRequest->fromPlayer->id->equals($recipient->id) === false
            ) {
                throw new PartnerRequestNotFound();
            }

            $isRequestReply = true;
        }

        // The owner answering an "ask to borrow": only to whoever asked them
        if ($message->borrowRequestId !== null) {
            $borrowRequest = $this->borrowRequestRepository->get($message->borrowRequestId);

            if (
                $borrowRequest->owner->id->equals($initiator->id) === false
                || $borrowRequest->asker->id->equals($recipient->id) === false
            ) {
                throw new BorrowRequestNotFound();
            }

            $isRequestReply = true;
        }

        // For general conversations, check if recipient allows direct messages - the recipient of a partner finder
        // reply or of a borrow answer asked first, so their setting does not apply
        if (!$isMarketplace && !$isRequestReply && !$recipient->allowDirectMessages) {
            throw new DirectMessagesDisabled();
        }

        // Check if accepted conversation already exists between these two users (in either direction)
        $existingAccepted = $this->conversationRepository->findAcceptedBetween($initiator, $recipient);

        if ($existingAccepted !== null) {
            if ($isMarketplace) {
                // Auto-accept new marketplace conversations when already accepted
                $now = $this->clock->now();
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

                if (!$isBlocked) {
                    try {
                        $this->mercureNotifier->notifyNewMessage($chatMessage);
                        $this->mercureNotifier->notifyNewConversationRequest($conversation);
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to send Mercure notification for new marketplace conversation', [
                            'conversationId' => $conversation->id->toString(),
                            'exception' => $e,
                        ]);
                    }
                }

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

        if ($isRequestReply) {
            $this->startRequestReply($initiator, $recipient, $message->initialMessage, $isBlocked);

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

        $now = $this->clock->now();

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

        if (!$isBlocked) {
            $notification = new Notification(
                Uuid::uuid7(),
                $recipient,
                NotificationType::NewConversationRequest,
                $this->clock->now(),
                targetConversation: $conversation,
            );
            $this->notificationRepository->save($notification);

            try {
                $this->mercureNotifier->notifyNewConversationRequest($conversation);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send Mercure notification for new conversation request', [
                    'conversationId' => $conversation->id->toString(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * The recipient asked first (to puzzle together, or to borrow puzzles), so the chat is open from the start: a request either of them sent
     * earlier is accepted, otherwise a new accepted conversation begins - no "conversation request" step for the person
     * who asked first. The accepted conversation between them is reused by the caller before this.
     */
    private function startRequestReply(Player $initiator, Player $recipient, string $initialMessage, bool $isBlocked): void
    {
        $pending = $this->conversationRepository->findPendingBetween($initiator, $recipient)
            ?? $this->conversationRepository->findPendingBetween($recipient, $initiator);

        if ($pending !== null) {
            $pending->accept($this->clock->now());

            $this->messageBus->dispatch(new SendMessage(
                conversationId: $pending->id->toString(),
                senderId: $initiator->id->toString(),
                content: $initialMessage,
            ));

            return;
        }

        $now = $this->clock->now();

        $conversation = new Conversation(
            id: Uuid::uuid7(),
            initiator: $initiator,
            recipient: $recipient,
            status: ConversationStatus::Accepted,
            createdAt: $now,
            respondedAt: $now,
            lastMessageAt: $now,
        );
        $this->conversationRepository->save($conversation);

        $chatMessage = new ChatMessage(
            id: Uuid::uuid7(),
            conversation: $conversation,
            sender: $initiator,
            content: $initialMessage,
            sentAt: $now,
        );
        $this->chatMessageRepository->save($chatMessage);

        if ($isBlocked) {
            return;
        }

        try {
            $this->mercureNotifier->notifyNewMessage($chatMessage);
            $this->mercureNotifier->notifyNewConversationRequest($conversation);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for a partner finder or borrow request reply', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
