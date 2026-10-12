<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Events\ChatMessageSent;
use SpeedPuzzling\Web\Exceptions\ChatMessageNotFound;
use SpeedPuzzling\Web\Navigation\InboxBadge;
use SpeedPuzzling\Web\Query\GetUserBlocks;
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
        private InboxBadge $inboxBadge,
        private GetUserBlocks $getUserBlocks,
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

        $isInitiator = $conversation->initiator?->id->toString() === $event->senderId;
        $otherParticipant = $isInitiator ? $conversation->recipient : $conversation->initiator;

        if ($otherParticipant === null) {
            return;
        }

        // If the sender is blocked by the other participant, skip notifications to them
        $isBlockedByOther = $this->getUserBlocks->isBlocked($otherParticipant->id->toString(), $event->senderId);

        try {
            if (!$isBlockedByOther) {
                $this->mercureNotifier->notifyNewMessage($chatMessage);

                // The other side's Inbox badge: an unread conversation, or a message request waiting for them (the
                // first message of a request is sent by its initiator)
                if (
                    $conversation->status === ConversationStatus::Accepted
                    || ($conversation->status === ConversationStatus::Pending && $isInitiator)
                ) {
                    $recipientId = $otherParticipant->id->toString();
                    $this->mercureNotifier->notifyInboxBadgeChanged($recipientId, $this->inboxBadge->forPlayer($recipientId));
                }

                $this->mercureNotifier->notifyConversationListChanged($otherParticipant->id->toString());
            }

            $this->mercureNotifier->notifyConversationListChanged($event->senderId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for message', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
