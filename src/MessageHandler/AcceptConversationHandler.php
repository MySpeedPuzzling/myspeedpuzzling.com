<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\AcceptConversation;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AcceptConversationHandler
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
    public function __invoke(AcceptConversation $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

        if ($conversation->recipient->id->toString() !== $message->playerId) {
            throw new ConversationNotFound();
        }

        if ($conversation->status !== ConversationStatus::Pending) {
            throw new ConversationNotFound();
        }

        $conversation->accept();

        try {
            $this->mercureNotifier->notifyConversationAccepted($conversation);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for conversation acceptance', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
