<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SolvingTimeGrave;
use SpeedPuzzling\Web\Entity\SolvingTimeTombstone;

/**
 * Graves and tombstones of deleted solving times (docs/features/api/solving-time-sync.md). Written only through
 * PuzzleSolvingTimeRepository::delete(); the deletes here are bulk on purpose - an erasure or a prune covers rows
 * nobody loads (the graves are never read by the app).
 */
readonly final class SolvingTimeGraveyardRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function bury(SolvingTimeGrave $grave): void
    {
        $this->entityManager->persist($grave);
    }

    public function addTombstone(SolvingTimeTombstone $tombstone): void
    {
        $this->entityManager->persist($tombstone);
    }

    /**
     * The time is back (Undo of an automatic removal) - it was not deleted after all. Its grave stays: a history.
     */
    public function removeTombstonesOf(string $solvingTimeId): void
    {
        $tombstones = $this->entityManager->getRepository(SolvingTimeTombstone::class)->findBy([
            'solvingTimeId' => Uuid::fromString($solvingTimeId),
        ]);

        foreach ($tombstones as $tombstone) {
            $this->entityManager->remove($tombstone);
        }
    }

    /**
     * Account deletion: everything kept of the player goes (right to erasure). Bulk - a player may have many.
     */
    public function eraseOfPlayer(string $playerId): void
    {
        foreach ([SolvingTimeGrave::class, SolvingTimeTombstone::class] as $entityClass) {
            $this->entityManager->createQueryBuilder()
                ->delete($entityClass, 'row')
                ->where('row.playerId = :playerId')
                ->setParameter('playerId', $playerId)
                ->getQuery()
                ->execute();
        }
    }

    /**
     * @return int graves and tombstones deleted
     */
    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        $deleted = 0;

        foreach ([SolvingTimeGrave::class, SolvingTimeTombstone::class] as $entityClass) {
            /** @var int $count */
            $count = $this->entityManager->createQueryBuilder()
                ->delete($entityClass, 'row')
                ->where('row.deletedAt < :before')
                ->setParameter('before', $before)
                ->getQuery()
                ->execute();

            $deleted += $count;
        }

        return $deleted;
    }
}
