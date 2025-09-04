<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Entity\PuzzleCollectionItem;

readonly final class PuzzleCollectionItemRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPlayerAndPuzzle(Player $player, Puzzle $puzzle): null|PuzzleCollectionItem
    {
        return $this->entityManager->getRepository(PuzzleCollectionItem::class)->findOneBy([
            'player' => $player,
            'puzzle' => $puzzle,
        ]);
    }

    public function existsInCustomCollection(Player $player, Puzzle $puzzle): bool
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(item.id)')
            ->from(PuzzleCollectionItem::class, 'item')
            ->leftJoin('item.collection', 'collection')
            ->where('item.player = :player')
            ->andWhere('item.puzzle = :puzzle')
            ->andWhere('(collection.systemType IS NULL OR collection.id IS NULL)')
            ->setParameter('player', $player)
            ->setParameter('puzzle', $puzzle);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findByCollectionAndPuzzle(PuzzleCollection $collection, Puzzle $puzzle): null|PuzzleCollectionItem
    {
        return $this->entityManager->getRepository(PuzzleCollectionItem::class)->findOneBy([
            'collection' => $collection,
            'puzzle' => $puzzle,
        ]);
    }
}
