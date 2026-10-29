<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendTeamChatMessage;
use SpeedPuzzling\Web\Repository\ChatMessageRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Continues the direct conversation between the two (a pending or ignored one is accepted), otherwise starts an
 * accepted one
 */
#[AsMessageHandler]
readonly final class SendTeamChatMessageHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ConversationRepository $conversationRepository,
        private ChatMessageRepository $chatMessageRepository,
        private MercureNotifier $mercureNotifier,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(SendTeamChatMessage $message): void
    {
        $sender = $this->playerRepository->get($message->senderId);
        $recipient = $this->playerRepository->get($message->recipientId);

        if ($sender->id->equals($recipient->id)) {
            throw new ConflictHttpException('The team cannot send a message to itself.');
        }

        $now = $this->clock->now();
        $conversation = $this->conversationRepository->findDirectBetween($sender, $recipient);
        $isNew = $conversation === null;

        if ($conversation === null) {
            $conversation = new Conversation(
                id: Uuid::uuid7(),
                initiator: $sender,
                recipient: $recipient,
                status: ConversationStatus::Accepted,
                createdAt: $now,
                respondedAt: $now,
                lastMessageAt: $now,
            );
            $this->conversationRepository->save($conversation);
        } elseif ($conversation->status !== ConversationStatus::Accepted) {
            $conversation->accept($now);
        }

        $chatMessage = new ChatMessage(
            id: Uuid::uuid7(),
            conversation: $conversation,
            sender: $sender,
            content: mb_substr($message->content, 0, SendTeamChatMessage::CONTENT_MAX_LENGTH),
            sentAt: $now,
        );
        $this->chatMessageRepository->save($chatMessage);
        $conversation->updateLastMessageAt($now);

        try {
            $this->mercureNotifier->notifyNewMessage($chatMessage);

            if ($isNew) {
                $this->mercureNotifier->notifyNewConversationRequest($conversation);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for a team chat message', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
