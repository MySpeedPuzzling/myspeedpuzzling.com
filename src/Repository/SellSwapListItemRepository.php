<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;

readonly final class SellSwapListItemRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     */
    public function get(string $sellSwapListItemId): SellSwapListItem
    {
        if (!Uuid::isValid($sellSwapListItemId)) {
            throw new SellSwapListItemNotFound();
        }

        $sellSwapListItem = $this->entityManager->find(SellSwapListItem::class, $sellSwapListItemId);

        if ($sellSwapListItem === null) {
            throw new SellSwapListItemNotFound();
        }

        return $sellSwapListItem;
    }

    /**
     * Several listings in one query - ids that do not exist (any more), or are no UUID, are left out.
     *
     * @param list<string> $sellSwapListItemIds
     * @return list<SellSwapListItem>
     */
    public function findMany(array $sellSwapListItemIds): array
    {
        $ids = [];

        foreach ($sellSwapListItemIds as $sellSwapListItemId) {
            if (Uuid::isValid($sellSwapListItemId)) {
                $ids[strtolower($sellSwapListItemId)] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<SellSwapListItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SellSwapListItem::class, 'i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_keys($ids))
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function findByPlayerAndPuzzle(Player $player, Puzzle $puzzle): null|SellSwapListItem
    {
        return $this->entityManager->getRepository(SellSwapListItem::class)
            ->findOneBy([
                'player' => $player,
                'puzzle' => $puzzle,
            ]);
    }

    public function countByPlayer(string $playerId): int
    {
        return $this->entityManager->getRepository(SellSwapListItem::class)
            ->count([
                'player' => $playerId,
            ]);
    }

    public function save(SellSwapListItem $sellSwapListItem): void
    {
        $this->entityManager->persist($sellSwapListItem);
    }

    public function delete(SellSwapListItem $sellSwapListItem): void
    {
        $this->entityManager->remove($sellSwapListItem);
    }
}
