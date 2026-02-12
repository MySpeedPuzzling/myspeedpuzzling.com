<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Exceptions\SoldSwappedItemNotFound;

readonly final class SoldSwappedItemRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SoldSwappedItemNotFound
     */
    public function get(string $id): SoldSwappedItem
    {
        $item = $this->entityManager->find(SoldSwappedItem::class, $id);

        if ($item === null) {
            throw new SoldSwappedItemNotFound();
        }

        return $item;
    }

    public function save(SoldSwappedItem $soldSwappedItem): void
    {
        $this->entityManager->persist($soldSwappedItem);
    }

    /**
     * @return array<SoldSwappedItem>
     */
    public function findByPlayerId(string $playerId): array
    {
        return $this->entityManager->getRepository(SoldSwappedItem::class)
            ->findBy(
                ['seller' => $playerId],
                ['soldAt' => 'DESC'],
            );
    }
}
