<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;

readonly final class PuzzleRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function get(string $puzzleId): Puzzle
    {
        if (!Uuid::isValid($puzzleId)) {
            throw new PuzzleNotFound();
        }

        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);

        return $puzzle ?? throw new PuzzleNotFound();
    }

    public function findById(UuidInterface $puzzleId): null|Puzzle
    {
        return $this->entityManager->find(Puzzle::class, $puzzleId);
    }

    /**
     * The puzzles locked for update until the transaction ends (SELECT … FOR UPDATE) - for a write derived from what
     * they hold now: an edit committing meanwhile waits instead of being overwritten. Needs an open transaction (the
     * doctrine_transaction middleware of a handler). Locked in id order, so two callers sharing puzzles do not
     * deadlock; a puzzle already loaded is refreshed with the row as it is once locked. Unknown ids are left out.
     *
     * @param list<string> $puzzleIds
     *
     * @return list<Puzzle>
     */
    public function findByIdsForUpdate(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<Puzzle> $puzzles */
        $puzzles = $this->entityManager
            ->createQuery('SELECT puzzle FROM ' . Puzzle::class . ' puzzle WHERE puzzle.id IN (:ids) ORDER BY puzzle.id')
            ->setParameter('ids', $puzzleIds)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $puzzles;
    }

    /**
     * @return list<Puzzle>
     */
    public function findByManufacturer(Manufacturer $manufacturer): array
    {
        return $this->entityManager->getRepository(Puzzle::class)->findBy(['manufacturer' => $manufacturer]);
    }

    public function countByManufacturer(Manufacturer $manufacturer): int
    {
        return $this->entityManager->getRepository(Puzzle::class)->count(['manufacturer' => $manufacturer]);
    }
}
