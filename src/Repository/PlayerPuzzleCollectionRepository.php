<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionFolder;
use SpeedPuzzling\Web\Entity\PlayerPuzzleCollection;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;

readonly final class PlayerPuzzleCollectionRepository
{
    private EntityRepository $repository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->repository = $entityManager->getRepository(PlayerPuzzleCollection::class);
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
        $this->entityManager->flush();
    }

    public function remove(PlayerPuzzleCollection $puzzleCollection): void
    {
        $this->entityManager->remove($puzzleCollection);
    }

    public function findByPlayerAndPuzzle(string $playerId, string $puzzleId): null|PlayerPuzzleCollection
    {
        return $this->repository->findOneBy([
            'player' => $playerId,
            'puzzle' => $puzzleId,
        ]);
    }

    public function moveAllPuzzlesFromFolderToRoot(CollectionFolder $folder): void
    {
        $collections = $this->repository->findBy(['folder' => $folder]);

        foreach ($collections as $collection) {
            $collection->moveToFolder(null);
        }

        $this->entityManager->flush();
    }

    public function delete(PlayerPuzzleCollection $collection): void
    {
        $this->entityManager->remove($collection);
        $this->entityManager->flush();
    }
}
