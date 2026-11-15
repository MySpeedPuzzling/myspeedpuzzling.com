<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportPerson;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * The people of an import (docs/features/data-import/people.md) - and what the limits and the partners'
 * notifications count: the registered partners on the results an import made. Those are asked in DQL, through the
 * rows' links and the pairs'/teams' members - nothing here names the results' table.
 */
readonly final class DataImportPersonRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DataImportPerson $person): void
    {
        $this->entityManager->persist($person);
    }

    public function delete(DataImportPerson $person): void
    {
        $this->entityManager->remove($person);
    }

    public function find(string $personId): null|DataImportPerson
    {
        return Uuid::isValid($personId) ? $this->entityManager->find(DataImportPerson::class, $personId) : null;
    }

    /**
     * @return list<DataImportPerson> the most named first
     */
    public function ofImport(DataImport $import): array
    {
        /** @var list<DataImportPerson> $people */
        $people = $this->entityManager->createQueryBuilder()
            ->select('person', 'player')
            ->from(DataImportPerson::class, 'person')
            ->leftJoin('person.player', 'player')
            ->where('person.import = :import')
            ->setParameter('import', $import)
            ->orderBy('person.rowsCount', 'DESC')
            ->addOrderBy('person.personKey', 'ASC')
            ->getQuery()
            ->getResult();

        return $people;
    }

    /**
     * @param list<string> $personKeys
     *
     * @return array<string, DataImportPerson> by person key
     */
    public function ofImportByKeys(DataImport $import, array $personKeys): array
    {
        if ($personKeys === []) {
            return [];
        }

        /** @var list<DataImportPerson> $people */
        $people = $this->entityManager->createQueryBuilder()
            ->select('person', 'player')
            ->from(DataImportPerson::class, 'person')
            ->leftJoin('person.player', 'player')
            ->where('person.import = :import')
            ->andWhere('person.personKey IN (:keys)')
            ->setParameter('import', $import)
            ->setParameter('keys', $personKeys)
            ->getQuery()
            ->getResult();

        $byKey = [];

        foreach ($people as $person) {
            $byKey[$person->personKey] = $person;
        }

        return $byKey;
    }

    /**
     * Every distinct text the import's result rows name among their people, as the rows are parsed now: how many
     * rows, and the first two of them. One statement whatever the import's size.
     *
     * @return list<array{text: string, rows: int, exampleRowIds: list<string>}>
     */
    public function distinctPeopleOfRows(DataImport $import): array
    {
        /** @var list<array{person: string, rows_count: int, example_row_ids: null|string}> $rows */
        $rows = $this->entityManager->getConnection()->executeQuery(<<<SQL
SELECT
    named.person,
    COUNT(*) AS rows_count,
    array_to_string((array_agg(data_import_row.id::text ORDER BY data_import_row.id))[1:2], ',') AS example_row_ids
FROM data_import_row
CROSS JOIN LATERAL jsonb_array_elements_text(
    CASE WHEN jsonb_typeof(data_import_row.parsed -> 'people') = 'array' THEN data_import_row.parsed -> 'people' ELSE '[]'::jsonb END
) AS named(person)
WHERE data_import_row.import_id = :importId
    AND data_import_row.kind IN (:result, :both)
    AND data_import_row.state <> :leftOut
GROUP BY named.person
ORDER BY named.person
SQL, [
            'importId' => $import->id->toString(),
            'result' => DataImportRowKind::Result->value,
            'both' => DataImportRowKind::Both->value,
            'leftOut' => DataImportRowState::LeftOut->value,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'text' => $row['person'],
            'rows' => (int) $row['rows_count'],
            'exampleRowIds' => $row['example_row_ids'] === null || $row['example_row_ids'] === '' ? [] : explode(',', $row['example_row_ids']),
        ], $rows);
    }

    /**
     * The registered partners - everybody but the importing player - on the results the batch's rows made, with the
     * number of those results each is on.
     *
     * @return array<string, int> player id => results
     */
    public function partnerResultsOfBatch(string $batchId, string $importerId): array
    {
        /** @var list<array{partnerId: string, results: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(teamMember.player) AS partnerId', 'COUNT(DISTINCT r.id) AS results')
            ->from(DataImportRow::class, 'r')
            ->join(PuzzleSolvingTime::class, 'time', 'WITH', 'time.id = r.solvingTimeId')
            ->join(PuzzlingTeamMember::class, 'teamMember', 'WITH', 'teamMember.team = time.puzzlingTeam')
            ->where('r.batchId = :batch')
            ->andWhere('r.resultMade = true')
            ->andWhere('teamMember.player IS NOT NULL')
            ->andWhere('teamMember.player != :importer')
            ->setParameter('batch', $batchId)
            ->setParameter('importer', $importerId)
            ->groupBy('teamMember.player')
            ->getQuery()
            ->getArrayResult();

        $byPartner = [];

        foreach ($rows as $row) {
            $byPartner[strtolower((string) $row['partnerId'])] = (int) $row['results'];
        }

        return $byPartner;
    }

    /**
     * The results the importing player's imports made - tracked by them, made by a spreadsheet import - that this
     * player is a registered person of: what "take me off them" goes through.
     *
     * @return list<string>
     */
    public function importedResultIdsWith(string $importerId, string $playerId): array
    {
        /** @var list<array{id: \Ramsey\Uuid\UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('time.id')
            ->from(PuzzleSolvingTime::class, 'time')
            ->join(PuzzlingTeamMember::class, 'teamMember', 'WITH', 'teamMember.team = time.puzzlingTeam')
            ->where('time.player = :importer')
            ->andWhere('time.createdVia = :import')
            ->andWhere('teamMember.player = :player')
            ->setParameter('importer', $importerId)
            ->setParameter('import', SolvingTimeSource::SpreadsheetImport)
            ->setParameter('player', $playerId)
            ->orderBy('time.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }
}
