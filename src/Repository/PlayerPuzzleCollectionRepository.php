<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerPuzzleCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;

readonly final class PlayerPuzzleCollectionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function get(string $playerId, string $puzzleId): PlayerPuzzleCollection
    {
        if (!Uuid::isValid($puzzleId)) {
            throw new PuzzleNotFound();
        }

        $collections = $this->entityManager->createQueryBuilder()
            ->select('ppc')
            ->from(PlayerPuzzleCollection::class, 'ppc')
            ->where('ppc.player = :playerId')
            ->andWhere('ppc.puzzle = :puzzleId')
            ->setParameter('playerId', $playerId)
            ->setParameter('puzzleId', $puzzleId)
            ->getQuery()
            ->getResult();

        return $collections[0] ?? throw new PuzzleNotFound();
    }

    public function save(PlayerPuzzleCollection $puzzleCollection): void
    {
        $this->entityManager->persist($puzzleCollection);
    }

    public function remove(PlayerPuzzleCollection $puzzleCollection): void
    {
        $this->entityManager->remove($puzzleCollection);
    }
}
