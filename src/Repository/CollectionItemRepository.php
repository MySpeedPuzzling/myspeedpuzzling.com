<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;

readonly final class CollectionItemRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CollectionItemNotFound
     */
    public function get(string $collectionItemId): CollectionItem
    {
        if (!Uuid::isValid($collectionItemId)) {
            throw new CollectionItemNotFound();
        }

        $collectionItem = $this->entityManager->find(CollectionItem::class, $collectionItemId);

        if ($collectionItem === null) {
            throw new CollectionItemNotFound();
        }

        return $collectionItem;
    }

    /**
     * @return array<CollectionItem>
     */
    public function findByCollectionAndPlayer(null|Collection $collection, Player $player): array
    {
        return $this->entityManager->getRepository(CollectionItem::class)
            ->findBy([
                'collection' => $collection,
                'player' => $player,
            ]);
    }

    /**
     * @return array<CollectionItem>
     */
    public function findByPlayerAndPuzzle(string $playerId, string $puzzleId): array
    {
        return $this->entityManager->getRepository(CollectionItem::class)
            ->findBy([
                'player' => $playerId,
                'puzzle' => $puzzleId,
            ]);
    }

    public function findByCollectionPlayerAndPuzzle(null|Collection $collection, Player $player, Puzzle $puzzle): null|CollectionItem
    {
        return $this->entityManager->getRepository(CollectionItem::class)
            ->findOneBy([
                'collection' => $collection,
                'player' => $player,
                'puzzle' => $puzzle,
            ]);
    }

    public function countByCollection(null|Collection $collection, Player $player): int
    {
        return $this->entityManager->getRepository(CollectionItem::class)
            ->count([
                'collection' => $collection,
                'player' => $player,
            ]);
    }

    public function save(CollectionItem $collectionItem): void
    {
        $this->entityManager->persist($collectionItem);
    }

    public function delete(CollectionItem $collectionItem): void
    {
        $this->entityManager->remove($collectionItem);
    }
}
