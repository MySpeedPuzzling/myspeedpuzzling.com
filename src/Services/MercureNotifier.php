<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\Conversation;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;

final class MercureNotifier implements ResetInterface
{
    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    public function notifyNewConversationRequest(Conversation $conversation): void
    {
        $this->hub->publish(new Update(
            '/conversations/' . $conversation->recipient->id->toString(),
            json_encode([
                'type' => 'new_request',
                'conversationId' => $conversation->id->toString(),
                'initiatorId' => $conversation->initiator->id->toString(),
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public function notifyConversationAccepted(Conversation $conversation): void
    {
        $this->hub->publish(new Update(
            '/conversations/' . $conversation->initiator->id->toString(),
            json_encode([
                'type' => 'accepted',
                'conversationId' => $conversation->id->toString(),
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public function notifyConversationDenied(Conversation $conversation): void
    {
        $this->hub->publish(new Update(
            '/conversations/' . $conversation->initiator->id->toString(),
            json_encode([
                'type' => 'denied',
                'conversationId' => $conversation->id->toString(),
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public function notifyNewMessage(ChatMessage $chatMessage): void
    {
        $this->hub->publish(new Update(
            '/messages/' . $chatMessage->conversation->id->toString(),
            json_encode([
                'type' => 'new_message',
                'messageId' => $chatMessage->id->toString(),
                'senderId' => $chatMessage->sender->id->toString(),
                'content' => $chatMessage->content,
                'sentAt' => $chatMessage->sentAt->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public function notifyUnreadCountChanged(string $playerId, int $count): void
    {
        $this->hub->publish(new Update(
            '/unread-count/' . $playerId,
            json_encode([
                'type' => 'unread_count',
                'count' => $count,
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public function reset(): void
    {
        // No cached state to reset
    }
}
