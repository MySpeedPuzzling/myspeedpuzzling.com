<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleBorrowing;

readonly final class PuzzleBorrowingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findActiveBorrowing(Player $player, Puzzle $puzzle): null|PuzzleBorrowing
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b')
            ->from(PuzzleBorrowing::class, 'b')
            ->where('b.puzzle = :puzzle')
            ->andWhere('(b.owner = :player OR b.borrower = :player)')
            ->andWhere('b.returnedAt IS NULL')
            ->setParameter('puzzle', $puzzle)
            ->setParameter('player', $player)
            ->setMaxResults(1);

        /** @var PuzzleBorrowing|null */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return PuzzleBorrowing[]
     */
    public function findActiveByPlayer(Player $player): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b')
            ->from(PuzzleBorrowing::class, 'b')
            ->where('b.owner = :player OR b.borrower = :player')
            ->andWhere('b.returnedAt IS NULL')
            ->setParameter('player', $player);

        return $qb->getQuery()->getResult();
    }

    public function findActiveByOwnerAndPuzzle(Player $owner, Puzzle $puzzle): null|PuzzleBorrowing
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b')
            ->from(PuzzleBorrowing::class, 'b')
            ->where('b.owner = :owner')
            ->andWhere('b.puzzle = :puzzle')
            ->andWhere('b.returnedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('puzzle', $puzzle)
            ->setMaxResults(1);

        /** @var PuzzleBorrowing|null */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return PuzzleBorrowing[]
     */
    public function findAllActiveByOwnerAndPuzzle(Player $owner, Puzzle $puzzle): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b')
            ->from(PuzzleBorrowing::class, 'b')
            ->where('b.owner = :owner')
            ->andWhere('b.puzzle = :puzzle')
            ->andWhere('b.returnedAt IS NULL')
            ->andWhere('b.borrowedFrom = false')  // Only "borrowed to" records
            ->setParameter('owner', $owner)
            ->setParameter('puzzle', $puzzle)
            ->orderBy('b.borrowedAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    public function findActiveByBorrowerAndPuzzle(Player $borrower, Puzzle $puzzle): null|PuzzleBorrowing
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b')
            ->from(PuzzleBorrowing::class, 'b')
            ->where('b.borrower = :borrower')
            ->andWhere('b.puzzle = :puzzle')
            ->andWhere('b.returnedAt IS NULL')
            ->setParameter('borrower', $borrower)
            ->setParameter('puzzle', $puzzle)
            ->setMaxResults(1);

        /** @var PuzzleBorrowing|null */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return PuzzleBorrowing[]
     */
    public function findAllActiveByBorrowerAndPuzzle(Player $borrower, Puzzle $puzzle): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b')
            ->from(PuzzleBorrowing::class, 'b')
            ->where('b.borrower = :borrower')
            ->andWhere('b.puzzle = :puzzle')
            ->andWhere('b.returnedAt IS NULL')
            ->andWhere('b.borrowedFrom = true')  // Only "borrowed from" records
            ->setParameter('borrower', $borrower)
            ->setParameter('puzzle', $puzzle)
            ->orderBy('b.borrowedAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return PuzzleBorrowing[]
     */
    public function findAllActiveBorrowingsForPuzzle(Player $player, Puzzle $puzzle): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b')
            ->from(PuzzleBorrowing::class, 'b')
            ->where('b.puzzle = :puzzle')
            ->andWhere('(b.owner = :player OR b.borrower = :player)')
            ->andWhere('b.returnedAt IS NULL')
            ->setParameter('puzzle', $puzzle)
            ->setParameter('player', $player)
            ->orderBy('b.borrowedAt', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
