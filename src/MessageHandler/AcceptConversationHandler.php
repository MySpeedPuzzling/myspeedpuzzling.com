<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\AcceptConversation;
use SpeedPuzzling\Web\Message\PushInboxBadge;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

#[AsMessageHandler]
readonly final class AcceptConversationHandler
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private MercureNotifier $mercureNotifier,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws ConversationNotFound
     */
    public function __invoke(AcceptConversation $message): void
    {
        $conversation = $this->conversationRepository->get($message->conversationId);

        if ($conversation->recipient?->id->toString() !== $message->playerId) {
            throw new ConversationNotFound();
        }

        if (!in_array($conversation->status, [ConversationStatus::Pending, ConversationStatus::Ignored], true)) {
            throw new ConversationNotFound();
        }

        $conversation->accept();

        // The request left the player's Messages part: their other open tabs get the new badge (INB-02 review Q2)
        $this->messageBus->dispatch(new PushInboxBadge($message->playerId), [new DispatchAfterCurrentBusStamp()]);

        try {
            $this->mercureNotifier->notifyConversationAccepted($conversation);

            if ($conversation->initiator !== null) {
                $this->mercureNotifier->notifyConversationListChanged($conversation->initiator->id->toString());
            }

            if ($conversation->recipient !== null) {
                $this->mercureNotifier->notifyConversationListChanged($conversation->recipient->id->toString());
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notification for conversation acceptance', [
                'conversationId' => $conversation->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
