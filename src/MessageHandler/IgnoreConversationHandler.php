<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\IgnoreConversation;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class IgnoreConversationHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private MercureNotifier $mercureNotifier,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ConversationNotFound
     */
    public function __invoke(IgnoreConversation $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

        if ($conversation->recipient->id->toString() !== $message->playerId) {
            throw new ConversationNotFound();
        }

        if ($conversation->status !== ConversationStatus::Pending) {
            throw new ConversationNotFound();
        }

        $conversation->ignore();

        try {
            $this->mercureNotifier->notifyConversationIgnored($conversation);
            $this->mercureNotifier->notifyConversationListChanged($conversation->initiator->id->toString());
            $this->mercureNotifier->notifyConversationListChanged($conversation->recipient->id->toString());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for conversation ignore', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
