<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;

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
    public function linkSolvingTime(string $rowId, null|string $expected, string $solvingTimeId, DateTimeImmutable $madeAt): bool
    {
        // `result_made_at` goes with the link: the moment the result became a result of the profile - what the daily
        // guard counts, for an "Add" and for the pairing of an unlisted puzzle alike
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE data_import_row SET solving_time_id = :new, result_made_at = :madeAt WHERE id = :id AND solving_time_id IS NOT DISTINCT FROM CAST(:expected AS UUID)',
            ['new' => $solvingTimeId, 'id' => $rowId, 'expected' => $expected, 'madeAt' => $madeAt->format('Y-m-d H:i:s')],
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
     * The waiting rows of the import that are the same result as this row - an equal row of its sheet, the same row
     * on a later sheet, at most two steps away (README D6). The row that stands for them is not among them.
     *
     * @return list<DataImportRow>
     */
    public function equalRowsOf(DataImport $import, string $standingRowId): array
    {
        if (Uuid::isValid($standingRowId) === false) {
            return [];
        }

        /** @var list<DataImportRow> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(DataImportRow::class, 'r')
            ->join('r.sameAsRow', 'same')
            ->where('r.import = :import')
            ->andWhere('same.id = :standing OR IDENTITY(same.sameAsRow) = :standing')
            // Only rows a word still makes sense for: an "Add" holds the others, or they made something already
            ->andWhere('r.state = :waiting')
            ->andWhere('r.kind IN (:kinds)')
            ->orderBy('r.id')
            ->setParameter('import', $import)
            ->setParameter('standing', $standingRowId)
            ->setParameter('waiting', DataImportRowState::Waiting->value)
            ->setParameter('kinds', [DataImportRowKind::Result->value, DataImportRowKind::Both->value])
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The catalogue puzzle each of the rows is paired with now - what a row's "add it anyway" is given for
     * (DataImportRow::addAnyway()). Rows whose puzzle is not decided as a catalogue puzzle are not in the answer.
     *
     * @param list<string> $rowIds
     *
     * @return array<string, string> puzzle id by row id
     */
    public function cataloguePuzzleIdsOf(DataImport $import, array $rowIds): array
    {
        $rowIds = array_values(array_filter($rowIds, Uuid::isValid(...)));

        if ($rowIds === []) {
            return [];
        }

        /** @var array<string, string> $puzzleIds */
        $puzzleIds = $this->entityManager->getConnection()->executeQuery(<<<SQL
SELECT r.id, puzzle.puzzle_id
FROM data_import_row r
INNER JOIN data_import_puzzle puzzle ON puzzle.id = r.import_puzzle_id
WHERE r.import_id = :importId
    AND r.id IN (:rowIds)
    AND puzzle.choice = 'catalogue'
    AND puzzle.puzzle_id IS NOT NULL
SQL, ['importId' => $import->id->toString(), 'rowIds' => $rowIds], ['rowIds' => ArrayParameterType::STRING])->fetchAllKeyValue();

        return $puzzleIds;
    }

    /**
     * The next rows of the import that wait with one reason (refused by an "Add" - of one "Add", or of all), by
     * their ids' order: what a group answer goes through, a chunk at a time.
     *
     * @return list<string>
     */
    public function idsRefusedWith(DataImport $import, string $code, null|string $batchId, null|string $afterRowId, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(DataImportRow::class, 'r')
            ->where('r.import = :import')
            ->andWhere('r.state = :waiting')
            ->andWhere('r.failure = :code')
            ->orderBy('r.id')
            ->setParameter('import', $import)
            ->setParameter('waiting', DataImportRowState::Waiting->value)
            ->setParameter('code', $code)
            ->setMaxResults($limit);

        if ($batchId !== null && Uuid::isValid($batchId)) {
            $query->andWhere('r.batchId = :batch')->setParameter('batch', $batchId);
        }

        if ($afterRowId !== null && Uuid::isValid($afterRowId)) {
            $query->andWhere('r.id > :after')->setParameter('after', $afterRowId);
        }

        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }

    public function countRefusedWith(DataImport $import, string $code, null|string $batchId): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(DataImportRow::class, 'r')
            ->where('r.import = :import')
            ->andWhere('r.state = :waiting')
            ->andWhere('r.failure = :code')
            ->setParameter('import', $import)
            ->setParameter('waiting', DataImportRowState::Waiting->value)
            ->setParameter('code', $code);

        if ($batchId !== null && Uuid::isValid($batchId)) {
            $query->andWhere('r.batchId = :batch')->setParameter('batch', $batchId);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * The next ready results of the import that carry a time the player has not said "it is right" about, by their
     * ids' order - what "these times are right" before an "Add" shows to the time verification, a chunk at a time.
     * "Ready" is the one definition (sqlReadyRows()). With a null limit: how many there are after the cursor.
     *
     * @return list<string>
     */
    public function idsOfTimedReady(DataImport $import, null|string $afterRowId, int $limit): array
    {
        $ready = self::sqlReadyRows();

        /** @var list<string> $ids */
        $ids = $this->entityManager->getConnection()->executeQuery(<<<SQL
WITH {$ready}
SELECT r.id
FROM ready
INNER JOIN data_import_row r ON r.id = ready.id
WHERE ready.has_result
    -- A row kept with the player's unlisted puzzles makes no result: the time verification has nothing to judge
    AND ready.choice = 'catalogue'
    AND ready.equal_row = FALSE
    AND COALESCE(CAST(r.parsed ->> 'seconds' AS INTEGER), 0) > 0
    AND r.answers -> 'addAnyway' ->> 'unusual_time' IS NULL
    AND (CAST(:after AS UUID) IS NULL OR r.id > CAST(:after AS UUID))
ORDER BY r.id
LIMIT :limit
SQL, [
            'importId' => $import->id->toString(),
            'explicit' => 0,
            'after' => $afterRowId !== null && Uuid::isValid($afterRowId) ? $afterRowId : null,
            'limit' => $limit,
        ])->fetchFirstColumn();

        return $ids;
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
     * **The one definition of "ready"** (docs/features/data-import/adding.md "Ready") - two CTEs for whoever needs
     * them: "Add" itself (queueReadyRows()) and the screens that say beforehand what an "Add" would take
     * (Query\GetImportAddScreen), so the two can never drift apart.
     *
     * - `chosen`: the rows of the import an "Add" may look at - data without a problem on a sheet that is in, not
     *   held by an Undo, waiting and not refused before (a refused or failed row only when it is asked for by name -
     *   `:explicit = 1`), or settled as "already yours" with a result that is gone since ("already yours" is what
     *   exists now; puzzle_solving_time is asked only whether the id exists - whatever the result's state, tracked
     *   or not);
     * - `ready`: of them, the rows whose puzzle is decided as a catalogue puzzle and - for a result - whose day is
     *   exact, and the rows whose puzzle is kept as an unlisted puzzle (`choice = 'unlisted'`, whatever the day's
     *   precision), in the file's order, with the running number of results for the profile (`results_so_far`: the
     *   daily guard takes the first N - unlisted results are private and not counted).
     *
     * Parameters: `:importId`, `:explicit` (0 / 1), and whatever $selection names.
     *
     * @param string $selection more conditions on the row `r` (" AND r.id IN (:rowIds)")
     */
    public static function sqlReadyRows(string $selection = ''): string
    {
        return <<<SQL
chosen AS (
    SELECT
        r.id,
        r.sheet_id,
        r.row_number,
        sheet.position,
        r.kind IN ('result', 'both') AS has_result,
        r.kind IN ('owned', 'both') AS has_owned,
        r.kind = 'wish_list' AS is_wish,
        puzzle.choice,
        puzzle.puzzle_id,
        -- Only a row with a result has a day to ask about (`parsed` is the widest thing a row holds)
        r.kind IN ('result', 'both') AND COALESCE(r.parsed ->> 'datePrecision', '') = 'day' AND r.parsed ->> 'date' IS NOT NULL AS exact_day,
        -- The same result as another row of the file: one result unless the player says otherwise (README D6)
        r.same_as_row_id IS NOT NULL AND r.add_as_separate = FALSE AS equal_row,
        r.state,
        r.already_yours_how,
        r.already_yours_solving_time_id
    FROM data_import_row r
    INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id
    INNER JOIN data_import_puzzle puzzle ON puzzle.id = r.import_puzzle_id
    WHERE r.import_id = :importId
        AND sheet.included
        AND r.kind IN ('result', 'owned', 'both', 'wish_list')
        AND r.problems IS NULL
        AND r.undo_id IS NULL
        AND r.source_key IS NOT NULL
        AND (
            (r.state = 'waiting' AND (r.failure IS NULL OR :explicit = 1))
            OR (r.state = 'failed' AND :explicit = 1)
            OR (
                r.state = 'already_yours'
                AND r.already_yours_solving_time_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM puzzle_solving_time gone WHERE gone.id = r.already_yours_solving_time_id)
            )
        )
        {$selection}
),
ready AS (
    SELECT
        chosen.*,
        -- Only results for the profile use the day up: an unlisted result is private until its puzzle is paired
        SUM(CASE WHEN chosen.has_result AND chosen.choice = 'catalogue' THEN 1 ELSE 0 END) OVER (ORDER BY chosen.position, chosen.row_number, chosen.id) AS results_so_far
    FROM chosen
    WHERE (
            chosen.choice = 'catalogue'
            AND chosen.puzzle_id IS NOT NULL
            AND (chosen.has_result = FALSE OR chosen.exact_day)
        )
        -- Kept as the player's own unlisted puzzle (docs/features/data-import/unlisted-puzzles.md): nothing public
        -- is made of the row, so a day known to the month or the year only is taken as it is
        OR chosen.choice = 'unlisted'
)
SQL;
    }

    /**
     * "Add": the rows of the selection that are ready are queued for the batch, in the file's order - a row is ready
     * when it is data without a problem, its sheet is in, its puzzle is decided as a catalogue puzzle and, for a
     * result, its day is exact - or its puzzle is kept as an unlisted puzzle
     * (docs/features/data-import/adding.md "Ready") - a row that waits, or one settled as
     * "already yours" whose result is gone since. One statement, so a row is queued by
     * one batch only: the second of two "Add"s finds it queued. At most $resultsLeft of the rows that carry a result
     * are taken (the daily guard); owned and wish-list rows are not counted.
     *
     * A bulk statement on purpose, in native SQL: "add everything that is ready" of an import is up to 50,000 rows
     * (DataImportLimits::MAX_ROWS) that must not be loaded as objects in a web request to change two columns each;
     * it writes nothing but `state`, `batch_id` and `failure`.
     *
     * @param null|list<string> $rowIds only these rows (also rows that were refused or failed before - asked for again)
     * @param null|list<string> $importPuzzleIds only the rows of these distinct puzzles
     *
     * @return array{queued: int, overDailyLimit: int, needsExactDay: int}
     */
    public function queueReadyRows(DataImport $import, string $batchId, null|array $rowIds, null|array $importPuzzleIds, int $resultsLeft): array
    {
        $explicit = $rowIds !== null || $importPuzzleIds !== null;
        $selection = '';
        $parameters = [
            'importId' => $import->id->toString(),
            'batchId' => $batchId,
            'resultsLeft' => max(0, $resultsLeft),
            'queued' => DataImportRowState::Queued->value,
            'explicit' => $explicit ? 1 : 0,
        ];
        $types = [];

        if ($rowIds !== null) {
            $selection .= ' AND r.id IN (:rowIds)';
            $parameters['rowIds'] = $rowIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $rowIds;
            $types['rowIds'] = ArrayParameterType::STRING;
        }

        if ($importPuzzleIds !== null) {
            $selection .= ' AND r.import_puzzle_id IN (:importPuzzleIds)';
            $parameters['importPuzzleIds'] = $importPuzzleIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $importPuzzleIds;
            $types['importPuzzleIds'] = ArrayParameterType::STRING;
        }

        $ready = self::sqlReadyRows($selection);

        /** @var false|array{queued: int|string, over_daily_limit: int|string, needs_exact_day: int|string} $counts */
        $counts = $this->entityManager->getConnection()->executeQuery(<<<SQL
WITH {$ready},
taken AS (
    UPDATE data_import_row
    SET state = :queued, batch_id = :batchId, failure = NULL
    FROM ready
    WHERE data_import_row.id = ready.id
        AND (ready.has_result = FALSE OR ready.choice = 'unlisted' OR ready.results_so_far <= :resultsLeft)
        AND data_import_row.state IN ('waiting', 'failed', 'already_yours')
    RETURNING data_import_row.id
)
SELECT
    (SELECT COUNT(*) FROM taken) AS queued,
    (SELECT COUNT(*) FROM ready WHERE ready.has_result AND ready.choice = 'catalogue' AND ready.results_so_far > :resultsLeft) AS over_daily_limit,
    (SELECT COUNT(*) FROM chosen WHERE chosen.choice = 'catalogue' AND chosen.puzzle_id IS NOT NULL AND chosen.has_result AND chosen.exact_day = FALSE) AS needs_exact_day
SQL, $parameters, $types)->fetchAssociative();

        return [
            'queued' => (int) ($counts['queued'] ?? 0),
            'overDailyLimit' => (int) ($counts['over_daily_limit'] ?? 0),
            'needsExactDay' => (int) ($counts['needs_exact_day'] ?? 0),
        ];
    }

    /**
     * How many results of the player's imports **became results of the profile** since $since - by an "Add" or by
     * the pairing of an unlisted puzzle, whenever their row was added (`result_made_at`, written with the link) - or
     * are queued for one right now: what the daily guard counts (README Q4). A result that was undone since is not
     * counted, and neither is a row kept with the player's unlisted puzzles - it is private until it is paired
     * (docs/features/data-import/unlisted-puzzles.md "The daily guard").
     */
    public function countResultsAddedOrQueuedSince(string $playerId, DateTimeImmutable $since): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(DataImportRow::class, 'r')
            ->join('r.import', 'i')
            ->where('i.player = :player')
            ->andWhere('r.kind IN (:kinds)')
            ->andWhere(
                // Queued for the profile - or a result made since $since (one made before the stamp existed: by its
                // row's own moment)
                '(r.state = :queued AND NOT EXISTS (SELECT 1 FROM ' . DataImportPuzzle::class . ' kept WHERE kept.id = r.importPuzzleId AND kept.choice = :unlisted))'
                . ' OR (r.solvingTimeId IS NOT NULL AND (r.resultMadeAt >= :since OR (r.resultMadeAt IS NULL AND r.state = :added AND r.addedAt >= :since)))',
            )
            ->setParameter('player', $playerId)
            ->setParameter('kinds', [DataImportRowKind::Result->value, DataImportRowKind::Both->value])
            ->setParameter('queued', DataImportRowState::Queued->value)
            ->setParameter('added', DataImportRowState::Added->value)
            ->setParameter('unlisted', ImportPuzzleChoice::Unlisted->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }


    /**
     * The batch's next rows that still wait for their turn, in the file's order.
     *
     * @return list<string>
     */
    public function idsQueuedInBatch(string $batchId, int $limit, bool $onlyResults = false, null|string $afterId = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(DataImportRow::class, 'r')
            ->where('r.batchId = :batchId')
            ->andWhere('r.state = :queued')
            ->setParameter('batchId', $batchId)
            ->setParameter('queued', DataImportRowState::Queued->value)
            ->orderBy('r.id')
            ->setMaxResults($limit);

        if ($onlyResults) {
            $query->andWhere('r.kind IN (:kinds)')
                ->setParameter('kinds', [DataImportRowKind::Result->value, DataImportRowKind::Both->value]);
        }

        if ($afterId !== null) {
            $query->andWhere('r.id > :afterId')->setParameter('afterId', $afterId);
        }

        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }

    /**
     * What the rows of a batch say now: how many made something, were the player's already, were refused or failed,
     * and how many results, collection items and wish list items they made.
     *
     * @return array{queued: int, added: int, alreadyYours: int, refused: int, failed: int, results: int, collectionItems: int, wishListItems: int}
     */
    public function countsOfBatch(string $batchId): array
    {
        /** @var list<array{state: DataImportRowState, refused: int|string, rows: int|string, results: int|string, items: int|string, wishes: int|string}> $groups */
        $groups = $this->entityManager->createQueryBuilder()
            ->select('r.state AS state')
            ->addSelect('CASE WHEN r.failure IS NULL THEN 0 ELSE 1 END AS refused')
            ->addSelect('COUNT(r.id) AS rows')
            ->addSelect('SUM(CASE WHEN r.addedAt IS NOT NULL AND r.solvingTimeId IS NOT NULL AND r.resultMade = true THEN 1 ELSE 0 END) AS results')
            ->addSelect('SUM(CASE WHEN r.collectionItemId IS NULL THEN 0 ELSE 1 END) AS items')
            ->addSelect('SUM(CASE WHEN r.wishListItemId IS NULL THEN 0 ELSE 1 END) AS wishes')
            ->from(DataImportRow::class, 'r')
            ->where('r.batchId = :batchId')
            ->setParameter('batchId', $batchId)
            ->groupBy('r.state')
            ->addGroupBy('refused')
            ->getQuery()
            ->getArrayResult();

        $counts = ['queued' => 0, 'added' => 0, 'alreadyYours' => 0, 'refused' => 0, 'failed' => 0, 'results' => 0, 'collectionItems' => 0, 'wishListItems' => 0];

        foreach ($groups as $group) {
            $rows = (int) $group['rows'];

            match ($group['state']) {
                DataImportRowState::Queued => $counts['queued'] += $rows,
                DataImportRowState::Added => $counts['added'] += $rows,
                DataImportRowState::AlreadyYours => $counts['alreadyYours'] += $rows,
                DataImportRowState::Failed => $counts['failed'] += $rows,
                DataImportRowState::Waiting => $counts['refused'] += (int) $group['refused'] === 1 ? $rows : 0,
                default => null,
            };

            if ($group['state'] === DataImportRowState::Added) {
                $counts['results'] += (int) $group['results'];
                $counts['collectionItems'] += (int) $group['items'];
                $counts['wishListItems'] += (int) $group['wishes'];
            }
        }

        return $counts;
    }

    /**
     * The rows of a batch that made a result - for what is owed for the results once the batch is
     * through (ImportedResultsFollowUp).
     *
     * @return array<string, string> solving time id by row id, in the rows' order
     */
    public function solvingTimeIdsMadeByBatch(string $batchId, null|string $afterRowId = null, null|int $limit = null): array
    {
        /** @var list<array{id: UuidInterface, solvingTimeId: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.id', 'r.solvingTimeId')
            ->from(DataImportRow::class, 'r')
            ->where('r.batchId = :batchId')
            ->andWhere('r.id > :afterRowId')
            ->setParameter('afterRowId', $afterRowId ?? '00000000-0000-0000-0000-000000000000')
            ->andWhere('r.state = :added')
            ->andWhere('r.resultMade = true')
            ->andWhere('r.solvingTimeId IS NOT NULL')
            ->setParameter('batchId', $batchId)
            ->setParameter('added', DataImportRowState::Added->value)
            ->orderBy('r.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $made = [];

        foreach ($rows as $row) {
            $made[$row['id']->toString()] = $row['solvingTimeId']->toString();
        }

        return $made;
    }

    /**
     * Whether a batch still has rows of the player that wait for their turn - in any of the player's imports: one
     * "Add" of a player runs at a time.
     */
    public function hasQueuedRowsOfPlayer(string $playerId): bool
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(DataImportRow::class, 'r')
            ->join('r.import', 'i')
            ->where('i.player = :player')
            ->andWhere('r.state = :queued')
            ->setParameter('player', $playerId)
            ->setParameter('queued', DataImportRowState::Queued->value)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Which of the results are spoken for by a row of the player's imports: the result a row made, or the result a
     * row was settled as "already yours" with. A result on the profile pairs off with one row only (README "Never
     * twice", step 2).
     *
     * @param list<string> $solvingTimeIds
     *
     * @return array<string, true> by solving time id
     */
    public function solvingTimeIdsSpokenFor(string $playerId, array $solvingTimeIds): array
    {
        if ($solvingTimeIds === []) {
            return [];
        }

        /** @var list<array{solvingTimeId: null|UuidInterface, alreadyYoursSolvingTimeId: null|UuidInterface, state: DataImportRowState}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.solvingTimeId', 'r.alreadyYoursSolvingTimeId', 'r.state')
            ->from(DataImportRow::class, 'r')
            ->join('r.import', 'i')
            ->where('i.player = :player')
            ->andWhere('r.solvingTimeId IN (:ids) OR (r.alreadyYoursSolvingTimeId IN (:ids) AND r.state IN (:settled))')
            ->setParameter('player', $playerId)
            ->setParameter('ids', $solvingTimeIds)
            // On a waiting row the column is only the pairing's hint; a row that is settled or added speaks for it
            ->setParameter('settled', [DataImportRowState::AlreadyYours->value, DataImportRowState::Added->value])
            ->getQuery()
            ->getArrayResult();

        $spokenFor = [];

        foreach ($rows as $row) {
            if ($row['solvingTimeId'] !== null) {
                $spokenFor[$row['solvingTimeId']->toString()] = true;
            }

            if (in_array($row['state'], [DataImportRowState::AlreadyYours, DataImportRowState::Added], true) && $row['alreadyYoursSolvingTimeId'] !== null) {
                $spokenFor[$row['alreadyYoursSolvingTimeId']->toString()] = true;
            }
        }

        return $spokenFor;
    }

    /**
     * Undo: the rows of the selection that made something and are not being taken back already are marked for the
     * Undo. One statement - a row is taken back by one Undo only.
     *
     * A bulk statement on purpose (DQL): "everything this import added" is up to 50,000 rows that must not be loaded
     * to set one id each; it writes nothing but `undo_id`.
     *
     * @param null|list<string> $rowIds
     */
    public function claimForUndo(DataImport $import, string $undoId, null|string $batchId, null|array $rowIds): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->update(DataImportRow::class, 'r')
            ->set('r.undoId', ':undoId')
            ->where('r.import = :import')
            ->andWhere('r.state = :added')
            ->andWhere('r.undoId IS NULL')
            ->setParameter('undoId', $undoId)
            ->setParameter('import', $import)
            ->setParameter('added', DataImportRowState::Added->value);

        if ($batchId !== null) {
            $query->andWhere('r.batchId = :batchId')->setParameter('batchId', $batchId);
        }

        if ($rowIds !== null) {
            $query->andWhere('r.id IN (:rowIds)')->setParameter('rowIds', $rowIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $rowIds);
        }

        return (int) $query->getQuery()->execute();
    }

    /**
     * A batch is stopped: its rows that still wait for their turn wait again, as before the "Add". A bulk statement
     * on purpose (DQL): up to a whole import's rows, one column each.
     */
    public function releaseQueuedOfBatch(string $batchId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->update(DataImportRow::class, 'r')
            ->set('r.state', ':waiting')
            ->where('r.batchId = :batchId')
            ->andWhere('r.state = :queued')
            ->setParameter('waiting', DataImportRowState::Waiting->value)
            ->setParameter('queued', DataImportRowState::Queued->value)
            ->setParameter('batchId', $batchId)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<string>
     */
    public function idsOfUndo(string $undoId, int $limit): array
    {
        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(DataImportRow::class, 'r')
            ->where('r.undoId = :undoId')
            ->setParameter('undoId', $undoId)
            ->orderBy('r.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }

    /**
     * An Undo gave a row up (it threw): the row stays as it is - added - and is free for another Undo.
     */
    public function releaseFromUndo(string $rowId, string $undoId): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(DataImportRow::class, 'r')
            ->set('r.undoId', 'NULL')
            ->where('r.id = :rowId')
            ->andWhere('r.undoId = :undoId')
            ->setParameter('rowId', $rowId)
            ->setParameter('undoId', $undoId)
            ->getQuery()
            ->execute();
    }

    /**
     * Whether an import ever queued a row: such an import is never deleted whole - its rows' keys and links are what
     * "never twice" and Undo rest on.
     */
    public function hasRowsOfABatch(DataImport $import): bool
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(DataImportRow::class, 'r')
            ->where('r.import = :import')
            ->andWhere('r.batchId IS NOT NULL')
            ->setParameter('import', $import)
            ->getQuery()
            ->getSingleScalarResult() > 0;
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
