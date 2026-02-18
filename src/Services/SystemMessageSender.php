<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Value\SystemMessageType;

readonly final class SystemMessageSender
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private EntityManagerInterface $entityManager,
        private MercureNotifier $mercureNotifier,
        private GetConversations $getConversations,
        private LoggerInterface $logger,
    ) {
    }

    public function sendToAllConversations(
        SellSwapListItem $sellSwapListItem,
        SystemMessageType $type,
        null|UuidInterface $targetPlayerId = null,
    ): void {
        $conversations = $this->conversationRepository->findAllByListItem($sellSwapListItem);

        $now = new DateTimeImmutable();
        $affectedPlayerIds = [];

        foreach ($conversations as $conversation) {
            $chatMessage = new ChatMessage(
                id: Uuid::uuid7(),
                conversation: $conversation,
                sender: null,
                content: '',
                sentAt: $now,
                systemMessageType: $type,
                systemMessageTargetPlayerId: $targetPlayerId,
            );

            $this->entityManager->persist($chatMessage);
            $conversation->updateLastMessageAt($now);

            $this->mercureNotifier->notifySystemMessage($chatMessage);

            $affectedPlayerIds[] = $conversation->initiator->id->toString();
            $affectedPlayerIds[] = $conversation->recipient->id->toString();
        }

        $this->entityManager->flush();

        try {
            $affectedPlayerIds = array_unique($affectedPlayerIds);

            foreach ($affectedPlayerIds as $playerId) {
                $unreadCount = $this->getConversations->countUnreadForPlayer($playerId);
                $this->mercureNotifier->notifyUnreadCountChanged($playerId, $unreadCount);
                $this->mercureNotifier->notifyConversationListChanged($playerId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Mercure notifications for system message', [
                'exception' => $e,
            ]);
        }
    }
}
