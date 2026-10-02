<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;

readonly final class ResultDuplicatePreventionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResultDuplicatePrevention $prevention): void
    {
        $this->entityManager->persist($prevention);
    }

    /**
     * Which of the results a person saved although the add form told them the same time was already saved
     * ("It's another solve, save it") - a case of such a result is confirmed real from the start.
     *
     * @param list<string> $timeIds
     * @return array<string, true> keyed by "<playerId>|<timeId>"
     */
    public function savedAnywayKeys(array $timeIds): array
    {
        if ($timeIds === []) {
            return [];
        }

        /** @var list<array{playerId: string, timeId: string|UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.player) AS playerId', 'p.timeId AS timeId')
            ->from(ResultDuplicatePrevention::class, 'p')
            ->where('p.kind = :savedAnyway')
            ->andWhere('p.timeId IN (:timeIds)')
            ->setParameter('savedAnyway', DuplicatePreventionKind::SavedAnyway)
            ->setParameter('timeIds', $timeIds)
            ->getQuery()
            ->getScalarResult();

        $keys = [];

        foreach ($rows as $row) {
            $keys[$row['playerId'] . '|' . (string) $row['timeId']] = true;
        }

        return $keys;
    }
}
