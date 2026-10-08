<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Items of one collection (null = the system collection) among the given puzzles, with their puzzle loaded - one
     * statement for a whole selection. Ids that are not valid UUIDs are left out.
     *
     * @param array<string> $puzzleIds
     * @return array<string, CollectionItem> keyed by puzzle id
     */
    public function findByCollectionPlayerAndPuzzles(null|Collection $collection, Player $player, array $puzzleIds): array
    {
        $puzzleIds = array_values(array_filter($puzzleIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($puzzleIds === []) {
            return [];
        }

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('item', 'puzzle')
            ->from(CollectionItem::class, 'item')
            ->join('item.puzzle', 'puzzle')
            ->where('item.player = :player')
            ->andWhere('puzzle.id IN (:puzzleIds)')
            ->setParameter('player', $player->id->toString())
            ->setParameter('puzzleIds', $puzzleIds, ArrayParameterType::STRING);

        if ($collection === null) {
            $queryBuilder->andWhere('item.collection IS NULL');
        } else {
            $queryBuilder->andWhere('item.collection = :collection')
                ->setParameter('collection', $collection->id->toString());
        }

        /** @var array<CollectionItem> $items */
        $items = $queryBuilder->getQuery()->getResult();

        $byPuzzle = [];
        foreach ($items as $item) {
            $byPuzzle[$item->puzzle->id->toString()] = $item;
        }

        return $byPuzzle;
    }

    /**
     * The player's items of the selected puzzles in every collection, the system one included
     * (docs/features/collections/bulk-actions.md).
     *
     * @param list<string> $puzzleIds
     * @return list<CollectionItem>
     */
    public function findByPlayerAndPuzzles(Player $player, array $puzzleIds): array
    {
        $puzzleIds = array_values(array_filter($puzzleIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<CollectionItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('item', 'puzzle')
            ->from(CollectionItem::class, 'item')
            ->join('item.puzzle', 'puzzle')
            ->where('item.player = :player')
            ->andWhere('puzzle.id IN (:puzzleIds)')
            ->setParameter('player', $player->id->toString())
            ->setParameter('puzzleIds', $puzzleIds, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        return $items;
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
