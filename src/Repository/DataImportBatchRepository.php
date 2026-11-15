<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\DataImportBatch;
use SpeedPuzzling\Web\Entity\DataImportUndo;
use SpeedPuzzling\Web\Value\DataImportBatchState;

/**
 * The "Add"s and the Undos of the spreadsheet imports (docs/features/data-import/adding.md).
 */
readonly final class DataImportBatchRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DataImportBatch $batch): void
    {
        $this->entityManager->persist($batch);
    }

    public function saveUndo(DataImportUndo $undo): void
    {
        $this->entityManager->persist($undo);
    }

    public function find(string $batchId): null|DataImportBatch
    {
        return Uuid::isValid($batchId) ? $this->entityManager->find(DataImportBatch::class, $batchId) : null;
    }

    public function findUndo(string $undoId): null|DataImportUndo
    {
        return Uuid::isValid($undoId) ? $this->entityManager->find(DataImportUndo::class, $undoId) : null;
    }

    public function findByRequestId(string $requestId): null|DataImportBatch
    {
        return Uuid::isValid($requestId) ? $this->entityManager->getRepository(DataImportBatch::class)->findOneBy(['requestId' => $requestId]) : null;
    }

    public function findUndoByRequestId(string $requestId): null|DataImportUndo
    {
        return Uuid::isValid($requestId) ? $this->entityManager->getRepository(DataImportUndo::class)->findOneBy(['requestId' => $requestId]) : null;
    }

    /**
     * Batches that run and were not worked on since - a worker restarted, a job lost: the sweeper continues them.
     *
     * @return list<string>
     */
    public function idsRunningStandingSince(DateTimeImmutable $since, int $limit): array
    {
        return $this->idsStanding(DataImportBatch::class, $since, $limit);
    }

    /**
     * @return list<string>
     */
    public function undoIdsRunningStandingSince(DateTimeImmutable $since, int $limit): array
    {
        return $this->idsStanding(DataImportUndo::class, $since, $limit);
    }

    /**
     * @param class-string $entity
     *
     * @return list<string>
     */
    private function idsStanding(string $entity, DateTimeImmutable $since, int $limit): array
    {
        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('b.id')
            ->from($entity, 'b')
            ->where('b.state = :running')
            ->andWhere('b.lastActivityAt < :since')
            ->setParameter('running', DataImportBatchState::Running)
            ->setParameter('since', $since)
            ->orderBy('b.lastActivityAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }
}
