<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\ResultClaimLog;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultClaimAction;

readonly final class ResultClaimLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResultClaimLog $log): void
    {
        $this->entityManager->persist($log);
    }

    /**
     * How many times somebody else took the player's claim back - persisted rows of this request not yet included.
     */
    public function countRemovalsOf(UuidInterface $playerId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(log.id)')
            ->from(ResultClaimLog::class, 'log')
            ->where('log.playerId = :playerId')
            ->andWhere('log.action = :action')
            ->setParameter('playerId', $playerId->toString())
            ->setParameter('action', ResultClaimAction::Removed->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
