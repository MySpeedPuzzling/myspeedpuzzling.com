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
}
