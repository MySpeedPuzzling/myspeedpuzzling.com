<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportPileAnswer;

readonly final class DataImportPileAnswerRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DataImportPileAnswer $answer): void
    {
        $this->entityManager->persist($answer);
    }

    public function find(string $answerId): null|DataImportPileAnswer
    {
        return Uuid::isValid($answerId) ? $this->entityManager->find(DataImportPileAnswer::class, $answerId) : null;
    }

    /**
     * The answer that runs for this pile of the import - one at a time. Read under the import's lock by whoever
     * starts another.
     */
    public function findOpen(DataImport $import, string $pile): null|DataImportPileAnswer
    {
        /** @var list<DataImportPileAnswer> $open */
        $open = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(DataImportPileAnswer::class, 'a')
            ->where('a.import = :import')
            ->andWhere('a.pile = :pile')
            ->andWhere('a.finishedAt IS NULL')
            ->setParameter('import', $import)
            ->setParameter('pile', $pile)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $open[0] ?? null;
    }

    /**
     * Answers that run and gave no sign of life since - a worker restarted, a job lost: the sweeper ends them, so
     * their pile can be answered again.
     *
     * @return list<string>
     */
    public function idsOpenStandingSince(DateTimeImmutable $since, int $limit): array
    {
        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a.id')
            ->from(DataImportPileAnswer::class, 'a')
            ->where('a.finishedAt IS NULL')
            ->andWhere('a.lastActivityAt < :since')
            ->setParameter('since', $since)
            ->orderBy('a.lastActivityAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }
}
