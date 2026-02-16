<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Results\MessageView;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

final class MercureNotifier implements ResetInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly Environment $twig,
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
            private: true,
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
            private: true,
        ));
    }

    public function notifyConversationIgnored(Conversation $conversation): void
    {
        $this->hub->publish(new Update(
            '/conversations/' . $conversation->initiator->id->toString(),
            json_encode([
                'type' => 'ignored',
                'conversationId' => $conversation->id->toString(),
            ], JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    public function notifyNewMessage(ChatMessage $chatMessage): void
    {
        $senderId = $chatMessage->sender->id->toString();
        $conversation = $chatMessage->conversation;
        $recipientId = ($conversation->initiator->id->toString() === $senderId)
            ? $conversation->recipient->id->toString()
            : $conversation->initiator->id->toString();

        $messageView = new MessageView(
            messageId: $chatMessage->id->toString(),
            senderId: $senderId,
            senderName: $chatMessage->sender->name,
            senderAvatar: $chatMessage->sender->avatar,
            content: $chatMessage->content,
            sentAt: $chatMessage->sentAt,
            readAt: null,
            isOwnMessage: false,
        );

        $html = $this->twig->render('messaging/_new_message_stream.html.twig', [
            'message' => $messageView,
        ]);

        $this->hub->publish(new Update(
            '/messages/' . $conversation->id->toString() . '/user/' . $recipientId,
            $html,
            private: true,
        ));
    }

    public function notifyMessagesRead(string $conversationId, string $senderId): void
    {
        $this->hub->publish(new Update(
            '/conversation/' . $conversationId . '/read/' . $senderId,
            json_encode([
                'type' => 'read',
            ], JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    public function notifyConversationListChanged(string $playerId): void
    {
        $this->hub->publish(new Update(
            '/conversations/' . $playerId,
            json_encode([
                'type' => 'list_changed',
            ], JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    public function notifyUnreadCountChanged(string $playerId, int $count): void
    {
        $html = $this->twig->render('messaging/_unread_badge_stream.html.twig', [
            'count' => $count,
        ]);

        $this->hub->publish(new Update(
            '/unread-count/' . $playerId,
            $html,
            private: true,
        ));
    }

    public function reset(): void
    {
        // No cached state to reset
    }
}
