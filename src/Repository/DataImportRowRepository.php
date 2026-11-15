<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;

readonly final class DataImportRowRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DataImportRow $row): void
    {
        $this->entityManager->persist($row);
    }

    public function find(string $rowId): null|DataImportRow
    {
        if (Uuid::isValid($rowId) === false) {
            return null;
        }

        return $this->entityManager->find(DataImportRow::class, $rowId);
    }

    /**
     * The result the row made, **as the database has it now** - never from a loaded row, which may be older than
     * another transaction's write (ImportedResultWriter reads it after taking its lock).
     */
    public function linkedSolvingTimeId(string $rowId): null|string
    {
        /** @var list<array{solvingTimeId: null|UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.solvingTimeId')
            ->from(DataImportRow::class, 'r')
            ->where('r.id = :rowId')
            ->setParameter('rowId', $rowId)
            ->getQuery()
            ->getArrayResult();

        return ($rows[0]['solvingTimeId'] ?? null)?->toString();
    }

    /**
     * Names the result the row made - only if the row still names what the caller read ($expected: nothing, or a
     * result that is gone). False = somebody else linked the row meanwhile; nothing was written.
     *
     * Native SQL on purpose, the exception CLAUDE.md allows with its reason: this is a compare-and-set - one
     * statement whose WHERE is the check and whose row count is the answer. Changing the loaded object and flushing
     * would write whatever the object holds, with no way to say "only if nobody was faster", and only at the flush;
     * written here, at once, a later row of the same transaction sees the link too.
     */
    public function linkSolvingTime(string $rowId, null|string $expected, string $solvingTimeId): bool
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE data_import_row SET solving_time_id = :new WHERE id = :id AND solving_time_id IS NOT DISTINCT FROM CAST(:expected AS UUID)',
            ['new' => $solvingTimeId, 'id' => $rowId, 'expected' => $expected],
        ) === 1;
    }

    /**
     * The results other rows with the same key made for the player - in any of their imports (the same data in
     * another file, README "Never twice"). Ids only: whether such a result still exists is the caller's question.
     *
     * @return list<string>
     */
    public function solvingTimeIdsOfSourceKey(string $playerId, string $sourceKey, string $exceptRowId): array
    {
        /** @var list<array{solvingTimeId: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.solvingTimeId')
            ->from(DataImportRow::class, 'r')
            ->join('r.import', 'i')
            ->where('i.player = :player')
            ->andWhere('r.sourceKey = :sourceKey')
            ->andWhere('r.solvingTimeId IS NOT NULL')
            ->andWhere('r.id != :rowId')
            ->orderBy('r.id')
            ->setParameter('player', $playerId)
            ->setParameter('sourceKey', $sourceKey)
            ->setParameter('rowId', $exceptRowId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['solvingTimeId']->toString(), $rows);
    }

    /**
     * @param list<string> $rowIds
     *
     * @return array<string, DataImportRow> by id
     */
    public function ofSheetByIds(DataImportSheet $sheet, array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        /** @var list<DataImportRow> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(DataImportRow::class, 'r')
            ->where('r.sheet = :sheet')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('sheet', $sheet)
            ->setParameter('ids', $rowIds)
            ->getQuery()
            ->getResult();

        $byId = [];

        foreach ($rows as $row) {
            $byId[$row->id->toString()] = $row;
        }

        return $byId;
    }

    /**
     * A row by its id without loading it - for pointing another row at it.
     */
    public function reference(string $rowId): DataImportRow
    {
        return $this->entityManager->getReference(DataImportRow::class, $rowId)
            ?? throw new \LogicException('A reference is always given.');
    }

    /**
     * Links every row of the import to the row with the same key on an earlier sheet ("the same result on two
     * sheets is one result" - README D6) and unlinks rows whose twin is gone or changed. A row linked to an equal row
     * of its own sheet (ImportEqualRows) keeps that link.
     *
     * A bulk statement on purpose, in native SQL: it compares every row of an import (up to 50,000) with every
     * other by its key - loading them as objects to set one reference each would hold the import's lock for seconds
     * and the rows in memory. It writes nothing but `same_as_row_id`.
     */
    public function linkEqualRowsAcrossSheets(DataImport $import): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(<<<SQL
UPDATE data_import_row AS linked
SET same_as_row_id = found.twin_id
FROM (
    SELECT
        r.id,
        (
            SELECT twin.id
            FROM data_import_row twin
            INNER JOIN data_import_sheet twin_sheet ON twin_sheet.id = twin.sheet_id
            WHERE twin.import_id = r.import_id
                AND twin.source_key = r.source_key
                AND twin_sheet.position < sheet.position
            ORDER BY twin_sheet.position, twin.row_number
            LIMIT 1
        ) AS twin_id
    FROM data_import_row r
    INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id
    LEFT JOIN data_import_row current ON current.id = r.same_as_row_id
    WHERE r.import_id = :importId
        AND (current.id IS NULL OR current.sheet_id <> r.sheet_id)
) AS found
WHERE linked.id = found.id
    AND linked.same_as_row_id IS DISTINCT FROM found.twin_id
SQL, ['importId' => $import->id->toString()]);
    }

    /**
     * A discarded import's rows. A bulk delete on purpose: an import holds up to 50,000 rows
     * (DataImportLimits::MAX_ROWS) that must not be loaded one by one to be removed.
     */
    public function deleteOfImport(DataImport $import): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(DataImportRow::class, 'r')
            ->where('r.import = :import')
            ->setParameter('import', $import)
            ->getQuery()
            ->execute();
    }
}
