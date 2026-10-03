<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\SellSwapListItemEvent;

readonly final class SellSwapListItemEventRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(UuidInterface|string $listItemId, UuidInterface|string $competitionId): null|SellSwapListItemEvent
    {
        $listItemId = (string) $listItemId;
        $competitionId = (string) $competitionId;

        if (!Uuid::isValid($listItemId) || !Uuid::isValid($competitionId)) {
            return null;
        }

        return $this->entityManager->find(SellSwapListItemEvent::class, [
            'sellSwapListItem' => $listItemId,
            'competition' => $competitionId,
        ]);
    }

    /**
     * Every row of the player's listings for the event - also rows of listings that are not published
     * any more; the caller decides what they mean.
     *
     * @return list<SellSwapListItemEvent>
     */
    public function forPlayerAndCompetition(string $playerId, string $competitionId): array
    {
        if (!Uuid::isValid($playerId) || !Uuid::isValid($competitionId)) {
            return [];
        }

        /** @var list<SellSwapListItemEvent> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(SellSwapListItemEvent::class, 'e')
            ->join('e.sellSwapListItem', 'i')
            ->where('i.player = :playerId')
            ->andWhere('e.competition = :competitionId')
            ->setParameter('playerId', $playerId)
            ->setParameter('competitionId', $competitionId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Every event the listing is marked for - past events and events the seller left included.
     *
     * @return list<SellSwapListItemEvent>
     */
    public function forListItem(string $listItemId): array
    {
        if (!Uuid::isValid($listItemId)) {
            return [];
        }

        /** @var list<SellSwapListItemEvent> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(SellSwapListItemEvent::class, 'e')
            ->where('e.sellSwapListItem = :listItemId')
            ->setParameter('listItemId', $listItemId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(SellSwapListItemEvent $sellSwapListItemEvent): void
    {
        $this->entityManager->persist($sellSwapListItemEvent);
    }

    public function delete(SellSwapListItemEvent $sellSwapListItemEvent): void
    {
        $this->entityManager->remove($sellSwapListItemEvent);
    }
}
