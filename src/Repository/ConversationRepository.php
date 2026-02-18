<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Value\ConversationStatus;

readonly final class ConversationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ConversationNotFound
     */
    public function get(string $conversationId): Conversation
    {
        if (!Uuid::isValid($conversationId)) {
            throw new ConversationNotFound();
        }

        $conversation = $this->entityManager->find(Conversation::class, $conversationId);

        if ($conversation === null) {
            throw new ConversationNotFound();
        }

        return $conversation;
    }

    public function save(Conversation $conversation): void
    {
        $this->entityManager->persist($conversation);
    }

    public function findPendingBetween(Player $initiator, Player $recipient): null|Conversation
    {
        return $this->entityManager->getRepository(Conversation::class)
            ->findOneBy([
                'initiator' => $initiator,
                'recipient' => $recipient,
                'status' => ConversationStatus::Pending,
            ]);
    }

    public function findDirectBetween(Player $playerA, Player $playerB): null|Conversation
    {
        $qb = $this->entityManager->createQueryBuilder();

        $conversations = $qb->select('c')
            ->from(Conversation::class, 'c')
            ->where('((c.initiator = :playerA AND c.recipient = :playerB) OR (c.initiator = :playerB AND c.recipient = :playerA))')
            ->andWhere('c.sellSwapListItem IS NULL')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('playerA', $playerA)
            ->setParameter('playerB', $playerB)
            ->setParameter('statuses', [ConversationStatus::Accepted, ConversationStatus::Pending, ConversationStatus::Ignored])
            ->orderBy('c.lastMessageAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $conversations[0] ?? null;
    }

    public function findAcceptedBetween(Player $playerA, Player $playerB): null|Conversation
    {
        $qb = $this->entityManager->createQueryBuilder();

        $conversations = $qb->select('c')
            ->from(Conversation::class, 'c')
            ->where('((c.initiator = :playerA AND c.recipient = :playerB) OR (c.initiator = :playerB AND c.recipient = :playerA))')
            ->andWhere('c.status = :status')
            ->setParameter('playerA', $playerA)
            ->setParameter('playerB', $playerB)
            ->setParameter('status', ConversationStatus::Accepted)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $conversations[0] ?? null;
    }

    /**
     * @return array<Conversation>
     */
    public function findAllByListItem(SellSwapListItem $sellSwapListItem): array
    {
        return $this->entityManager->getRepository(Conversation::class)
            ->findBy([
                'sellSwapListItem' => $sellSwapListItem,
            ]);
    }

    public function findActiveByPlayersAndListing(Player $playerA, Player $playerB, SellSwapListItem $sellSwapListItem): null|Conversation
    {
        $qb = $this->entityManager->createQueryBuilder();

        $conversations = $qb->select('c')
            ->from(Conversation::class, 'c')
            ->where('((c.initiator = :playerA AND c.recipient = :playerB) OR (c.initiator = :playerB AND c.recipient = :playerA))')
            ->andWhere('c.sellSwapListItem = :sellSwapListItem')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('playerA', $playerA)
            ->setParameter('playerB', $playerB)
            ->setParameter('sellSwapListItem', $sellSwapListItem)
            ->setParameter('statuses', [ConversationStatus::Pending, ConversationStatus::Accepted, ConversationStatus::Ignored])
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $conversations[0] ?? null;
    }
}
