<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Value\DataImportStatus;

readonly final class DataImportRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DataImport $import): void
    {
        $this->entityManager->persist($import);
    }

    /**
     * The whole import - its sheets and rows go with it (foreign keys)
     */
    public function delete(DataImport $import): void
    {
        $this->entityManager->remove($import);
    }

    public function find(string $importId): null|DataImport
    {
        if (Uuid::isValid($importId) === false) {
            return null;
        }

        return $this->entityManager->find(DataImport::class, $importId);
    }

    /**
     * The player's own import - another player's is "not found" like one that does not exist.
     *
     * @throws DataImportNotFound
     */
    public function getOwnedBy(string $importId, string $playerId): DataImport
    {
        $import = $this->find($importId);

        if ($import === null || $import->isOwnedBy(strtolower($playerId)) === false) {
            throw new DataImportNotFound();
        }

        return $import;
    }

    public function countUnfinishedOf(Player $player): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(DataImport::class, 'i')
            ->where('i.player = :player')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('player', $player)
            ->setParameter('statuses', DataImportStatus::unfinished())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Uploads the browser never confirmed.
     *
     * @return list<DataImport>
     */
    public function uploadsStartedBefore(DateTimeImmutable $before, int $limit): array
    {
        /** @var list<DataImport> $imports */
        $imports = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(DataImport::class, 'i')
            ->where('i.status = :status')
            ->andWhere('i.createdAt < :before')
            ->setParameter('status', DataImportStatus::Uploading)
            ->setParameter('before', $before)
            ->orderBy('i.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $imports;
    }

    /**
     * Imports whose file is still in object storage although it was created before the moment given - a confirmed
     * upload or anything after it, never a discarded one (those go whole).
     *
     * @return list<DataImport>
     */
    public function withFileCreatedBefore(DateTimeImmutable $before, int $limit): array
    {
        /** @var list<DataImport> $imports */
        $imports = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(DataImport::class, 'i')
            ->where('i.fileKey IS NOT NULL')
            ->andWhere('i.status NOT IN (:statuses)')
            ->andWhere('i.createdAt < :before')
            ->setParameter('statuses', [DataImportStatus::Uploading, DataImportStatus::Discarded])
            ->setParameter('before', $before)
            ->orderBy('i.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $imports;
    }

    /**
     * @return list<DataImport>
     */
    public function discarded(int $limit): array
    {
        /** @var list<DataImport> $imports */
        $imports = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(DataImport::class, 'i')
            ->where('i.status = :status')
            ->setParameter('status', DataImportStatus::Discarded)
            ->orderBy('i.discardedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $imports;
    }
}
