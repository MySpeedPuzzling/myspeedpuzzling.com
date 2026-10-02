<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\ResultDuplicateCase;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;

readonly final class ResultDuplicateCaseRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResultDuplicateCase $case): void
    {
        $this->entityManager->persist($case);
    }

    /**
     * @return list<ResultDuplicateCase>
     */
    public function findOpen(): array
    {
        /** @var list<ResultDuplicateCase> $cases */
        $cases = $this->entityManager->getRepository(ResultDuplicateCase::class)
            ->findBy(['status' => DuplicateCaseStatus::Open]);

        return $cases;
    }

    /**
     * Every pair that ever had a case, in any status - such a pair is never raised again.
     *
     * @return array<string, true> keyed by ResultDuplicateCase::key()
     */
    public function allKeys(): array
    {
        /** @var list<array{playerId: string, timeAId: string|UuidInterface, timeBId: string|UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.player) AS playerId', 'c.timeAId AS timeAId', 'c.timeBId AS timeBId')
            ->from(ResultDuplicateCase::class, 'c')
            ->getQuery()
            ->getScalarResult();

        $keys = [];

        foreach ($rows as $row) {
            $keys[ResultDuplicateCase::key($row['playerId'], (string) $row['timeAId'], (string) $row['timeBId'])] = true;
        }

        return $keys;
    }
}
