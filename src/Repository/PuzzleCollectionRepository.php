<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;

readonly final class PuzzleCollectionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleCollectionNotFound
     */
    public function get(string $collectionId): PuzzleCollection
    {
        if (!Uuid::isValid($collectionId)) {
            throw new PuzzleCollectionNotFound();
        }

        $collection = $this->entityManager->find(PuzzleCollection::class, $collectionId);

        return $collection ?? throw new PuzzleCollectionNotFound();
    }

    public function findSystemCollection(Player $player, string $systemType): ?PuzzleCollection
    {
        return $this->entityManager->getRepository(PuzzleCollection::class)->findOneBy([
            'player' => $player,
            'systemType' => $systemType,
        ]);
    }

    public function findRootCollection(Player $player): ?PuzzleCollection
    {
        return $this->entityManager->getRepository(PuzzleCollection::class)->findOneBy([
            'player' => $player,
            'systemType' => null,
            'name' => null,
        ]);
    }
}