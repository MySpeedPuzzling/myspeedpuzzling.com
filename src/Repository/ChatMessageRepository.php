<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Exceptions\ChatMessageNotFound;

readonly final class ChatMessageRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ChatMessageNotFound
     */
    public function get(string $chatMessageId): ChatMessage
    {
        if (!Uuid::isValid($chatMessageId)) {
            throw new ChatMessageNotFound();
        }

        $chatMessage = $this->entityManager->find(ChatMessage::class, $chatMessageId);

        if ($chatMessage === null) {
            throw new ChatMessageNotFound();
        }

        return $chatMessage;
    }

    public function save(ChatMessage $chatMessage): void
    {
        $this->entityManager->persist($chatMessage);
    }
}
