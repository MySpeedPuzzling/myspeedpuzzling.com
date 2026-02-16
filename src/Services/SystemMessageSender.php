<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Value\SystemMessageType;

readonly final class SystemMessageSender
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private EntityManagerInterface $entityManager,
        private MercureNotifier $mercureNotifier,
    ) {
    }

    public function sendToAllConversations(
        SellSwapListItem $sellSwapListItem,
        SystemMessageType $type,
        null|UuidInterface $targetPlayerId = null,
    ): void {
        $conversations = $this->conversationRepository->findAllByListItem($sellSwapListItem);

        $now = new DateTimeImmutable();

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
        }

        $this->entityManager->flush();
    }
}
