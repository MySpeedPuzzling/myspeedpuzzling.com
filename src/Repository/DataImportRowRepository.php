<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Value\DataImportRowState;

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
     * Rows of one import by their ids, with their sheets - whatever sheet each is on (AnswerImportRows).
     *
     * @param list<string> $rowIds
     *
     * @return array<string, DataImportRow> by id
     */
    public function ofImportByIds(DataImport $import, array $rowIds): array
    {
        $rowIds = array_values(array_filter($rowIds, Uuid::isValid(...)));

        if ($rowIds === []) {
            return [];
        }

        /** @var list<DataImportRow> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r', 's')
            ->from(DataImportRow::class, 'r')
            ->join('r.sheet', 's')
            ->where('r.import = :import')
            ->andWhere('r.id IN (:ids)')
            ->orderBy('r.id')
            ->setParameter('import', $import)
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
     * The rows of a sheet that are equal by one of these keys **as the database has them** - a row changed in
     * memory is still found by the key it had (and handed back as the loaded object).
     *
     * @param list<string> $equalKeys
     *
     * @return list<DataImportRow>
     */
    public function ofSheetByEqualKeys(DataImportSheet $sheet, array $equalKeys, int $limit): array
    {
        if ($equalKeys === []) {
            return [];
        }

        /** @var list<DataImportRow> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(DataImportRow::class, 'r')
            ->where('r.sheet = :sheet')
            ->andWhere('r.equalKey IN (:keys)')
            ->orderBy('r.rowNumber')
            ->setMaxResults($limit)
            ->setParameter('sheet', $sheet)
            ->setParameter('keys', $equalKeys)
            ->getQuery()
            ->getResult();

        return $rows;
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
     * The pairing's hint "already yours" starts over for every waiting row of the import, and rows whose key made a
     * result in **another import of the player** - a result that still exists - are marked `earlier_import` with
     * that result ("the same data in another file", README "Never twice"). Rows that are no longer waiting keep what
     * they have. The hint for results typed by hand is storeProfileHints().
     *
     * A bulk statement on purpose, in native SQL: every waiting row of an import (up to 50,000) is compared with
     * every row the player's other imports made a result of; it writes nothing but the two hint columns. It reads
     * puzzle_solving_time only to ask whether a result exists - whatever its state (a result waiting for verification
     * exists too), with or without a tracker.
     */
    public function markRowsOfEarlierImports(DataImport $import): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(<<<SQL
UPDATE data_import_row
SET already_yours_how = found.how, already_yours_solving_time_id = found.solving_time_id
FROM (
    SELECT waiting.id, CASE WHEN made.solving_time_id IS NULL THEN NULL ELSE 'earlier_import' END AS how, made.solving_time_id
    FROM data_import_row waiting
    LEFT JOIN (
        SELECT DISTINCT ON (earlier.source_key) earlier.source_key, earlier.solving_time_id
        FROM data_import other
        INNER JOIN data_import_row earlier ON earlier.import_id = other.id
        INNER JOIN puzzle_solving_time ON puzzle_solving_time.id = earlier.solving_time_id
        WHERE other.player_id = :playerId
            AND other.id <> :importId
            AND earlier.source_key IS NOT NULL
        ORDER BY earlier.source_key, earlier.id
    ) AS made ON made.source_key = waiting.source_key
    WHERE waiting.import_id = :importId
        AND waiting.state = :waiting
) AS found
WHERE data_import_row.id = found.id
    AND (
        data_import_row.already_yours_how IS DISTINCT FROM found.how
        OR data_import_row.already_yours_solving_time_id IS DISTINCT FROM found.solving_time_id
    )
SQL, [
            'importId' => $import->id->toString(),
            'playerId' => $import->player->id->toString(),
            'waiting' => DataImportRowState::Waiting->value,
        ]);
    }

    /**
     * Rows that meet a result on the player's profile (ImportPuzzleMatcher worked out which) are marked `profile`
     * with that result - one statement for all of them, only rows that wait and carry no hint yet. Bulk for the
     * reason markRowsOfEarlierImports() gives.
     *
     * @param array<string, string> $solvingTimeIdByRowId
     */
    public function storeProfileHints(DataImport $import, array $solvingTimeIdByRowId): void
    {
        if ($solvingTimeIdByRowId === []) {
            return;
        }

        $hints = [];

        foreach ($solvingTimeIdByRowId as $rowId => $solvingTimeId) {
            $hints[] = ['id' => $rowId, 'solvingTimeId' => $solvingTimeId];
        }

        $this->entityManager->getConnection()->executeStatement(<<<SQL
UPDATE data_import_row
SET already_yours_how = 'profile', already_yours_solving_time_id = said."solvingTimeId"
FROM jsonb_to_recordset(CAST(:hints AS JSONB)) AS said(id UUID, "solvingTimeId" UUID)
WHERE data_import_row.id = said.id
    AND data_import_row.import_id = :importId
    AND data_import_row.state = :waiting
    AND data_import_row.already_yours_how IS NULL
SQL, [
            'importId' => $import->id->toString(),
            'hints' => json_encode($hints, JSON_THROW_ON_ERROR),
            'waiting' => DataImportRowState::Waiting->value,
        ]);
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
