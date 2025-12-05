<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;

readonly final class SoldSwappedItemRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
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
