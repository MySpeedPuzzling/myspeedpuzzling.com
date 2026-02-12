<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\DenyConversation;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DenyConversationHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private MercureNotifier $mercureNotifier,
    ) {
    }

    /**
     * @throws ConversationNotFound
     */
    public function __invoke(DenyConversation $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

        if ($conversation->recipient->id->toString() !== $message->playerId) {
            throw new ConversationNotFound();
        }

        if ($conversation->status !== ConversationStatus::Pending) {
            throw new ConversationNotFound();
        }

        $conversation->deny();

        $this->mercureNotifier->notifyConversationDenied($conversation);
    }
}
