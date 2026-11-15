<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
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

    /**
     * @param list<DataImportStatus> $statuses
     */
    public function countOf(Player $player, array $statuses): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(DataImport::class, 'i')
            ->where('i.player = :player')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('player', $player)
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Another import of the player that was read - or is being read - from the very same bytes. Not one that is
     * discarded, nor one whose reading failed (the new upload is then the player's next try).
     */
    public function findLiveOfSameFile(DataImport $import, string $sha256): null|DataImport
    {
        /** @var null|DataImport $same */
        $same = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(DataImport::class, 'i')
            ->where('i.player = :player')
            ->andWhere('i.fileSha256 = :sha256')
            ->andWhere('i.id <> :id')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('player', $import->player)
            ->setParameter('sha256', $sha256)
            ->setParameter('id', $import->id)
            ->setParameter('statuses', [DataImportStatus::Reading, DataImportStatus::SettingUp, DataImportStatus::Reviewing, DataImportStatus::Done])
            ->orderBy('i.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $same;
    }

    /**
     * Uploaded imports nobody started to read whose file is still there: never asked for (uploaded before reading
     * existed) and resting since `$restingSince`, or asked for before `$requestedBefore` (the message was lost).
     *
     * @return list<string> ids
     */
    public function idsWaitingToBeRead(DateTimeImmutable $restingSince, DateTimeImmutable $requestedBefore, int $limit): array
    {
        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.id')
            ->from(DataImport::class, 'i')
            ->where('i.status = :status')
            ->andWhere('i.fileKey IS NOT NULL')
            ->andWhere('(i.readRequestedAt IS NULL AND i.lastActivityAt < :resting) OR i.readRequestedAt < :requested')
            ->setParameter('status', DataImportStatus::Uploaded)
            ->setParameter('resting', $restingSince)
            ->setParameter('requested', $requestedBefore)
            ->orderBy('i.lastActivityAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }

    /**
     * Imports "being read" that wrote nothing since the moment given: their worker died.
     *
     * @return list<string> ids
     */
    public function idsReadingWithoutProgressSince(DateTimeImmutable $since, int $limit): array
    {
        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.id')
            ->from(DataImport::class, 'i')
            ->where('i.status = :status')
            ->andWhere('i.lastActivityAt < :since')
            ->setParameter('status', DataImportStatus::Reading)
            ->setParameter('since', $since)
            ->orderBy('i.lastActivityAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
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
    public function discardedBefore(DateTimeImmutable $before, int $limit): array
    {
        /** @var list<DataImport> $imports */
        $imports = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(DataImport::class, 'i')
            ->where('i.status = :status')
            ->andWhere('i.discardedAt < :before')
            ->setParameter('status', DataImportStatus::Discarded)
            ->setParameter('before', $before)
            ->orderBy('i.discardedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $imports;
    }
}
