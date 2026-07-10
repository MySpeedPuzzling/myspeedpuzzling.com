<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PuzzleRedirect;

readonly final class PuzzleRedirectRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PuzzleRedirect $puzzleRedirect): void
    {
        $this->entityManager->persist($puzzleRedirect);
    }

    public function findByOldPuzzleId(string $oldPuzzleId): null|PuzzleRedirect
    {
        return $this->entityManager->getRepository(PuzzleRedirect::class)
            ->findOneBy([
                'oldPuzzleId' => $oldPuzzleId,
            ]);
    }

    /**
     * Re-points existing redirect chains: redirects that targeted any of the given
     * (now deleted) puzzles are updated to target the new survivor puzzle.
     *
     * @param array<string> $deletedPuzzleIds
     */
    public function redirectToNewSurvivor(array $deletedPuzzleIds, UuidInterface $newSurvivorPuzzleId): void
    {
        if ($deletedPuzzleIds === []) {
            return;
        }

        $this->entityManager->createQueryBuilder()
            ->update(PuzzleRedirect::class, 'puzzleRedirect')
            ->set('puzzleRedirect.survivorPuzzleId', ':newSurvivorPuzzleId')
            ->where('puzzleRedirect.survivorPuzzleId IN (:deletedPuzzleIds)')
            ->setParameter('newSurvivorPuzzleId', $newSurvivorPuzzleId, UuidType::NAME)
            ->setParameter('deletedPuzzleIds', $deletedPuzzleIds)
            ->getQuery()
            ->execute();
    }
}
