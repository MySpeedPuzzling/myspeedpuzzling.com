<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Results\ImportAddSummary;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * What the "Add" screens of a spreadsheet import read (docs/features/data-import/adding-screens.md): what is ready
 * and what is not, the review before adding, one "Add" and one Undo with what came of them, and the lists of rows
 * that were added or could not be. **The signed-in player's own import only** - every statement names the player,
 * so another player's import, batch, undo or row is "not found" / an empty list.
 *
 * It reads the import's own tables; of `puzzle_solving_time` it asks only whether a result exists (by its id, or the
 * player's own result of a puzzle for "nothing to wish for") - whatever the result's state: one waiting for
 * verification (suspicious) is the player's own all the same, and nothing of a result is shown, counted or timed
 * here. Rows are the player's own (the import's player = the tracker), nobody else's identity is selected - a
 * result without a tracker is never the import's.
 *
 * "Ready" mirrors the one statement that queues (DataImportRowRepository::queueReadyRows(), adding.md "Ready") and
 * what a row would make mirrors ImportRowAdder - for showing only: the engine decides again, row by row, under the
 * player's lock. ImportAddScreensTest compares the numbers shown with what the real worker then adds.
 */
readonly final class GetImportAddScreen
{
    public const int ROWS_PER_PAGE = 50;

    /** The four questions a player may wave through per row (`answers.addAnyway`, adding.md "Refusals") */
    public const array WAVABLE = ['similar_result_exists', 'somebody_elses_result', 'said_already_on_profile', 'unusual_time'];

    /** Refused or threw: a row an "Add" took and did not add */
    private const string NOT_ADDED = "(r.state = 'failed' OR (r.state = 'waiting' AND r.failure IS NOT NULL))";

    /**
     * The rows "Add everything that is ready" may take, and of them (`ready`) the ones it does take - with what each
     * would make. Parameters: importId, playerId.
     */
    private const string READY_CTE = <<<SQL
import AS (
    SELECT data_import.id, data_import.status, data_import.cells_wiped_at
    FROM data_import
    WHERE data_import.id = :importId
        AND data_import.player_id = :playerId
        AND data_import.status <> 'discarded'
),
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
        COALESCE(r.parsed ->> 'datePrecision', '') = 'day' AND r.parsed ->> 'date' IS NOT NULL AS exact_day,
        r.same_as_row_id IS NOT NULL AND r.add_as_separate = FALSE AS equal_row,
        -- The pairing's hint, as the Puzzles summary counts it: the result is there, on the very puzzle
        r.state = 'waiting' AND r.already_yours_how IS NOT NULL AND EXISTS (
            SELECT 1
            FROM puzzle_solving_time known
            WHERE known.id = r.already_yours_solving_time_id
                AND (r.already_yours_how = 'earlier_import' OR known.puzzle_id = puzzle.puzzle_id)
        ) AS result_known
    FROM import
    INNER JOIN data_import_row r ON r.import_id = import.id
    INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id
    INNER JOIN data_import_puzzle puzzle ON puzzle.id = r.import_puzzle_id
    WHERE sheet.included
        AND r.kind IN ('result', 'owned', 'both', 'wish_list')
        AND r.problems IS NULL
        AND r.undo_id IS NULL
        AND r.source_key IS NOT NULL
        AND (
            (r.state = 'waiting' AND r.failure IS NULL)
            OR (
                r.state = 'already_yours'
                AND r.already_yours_solving_time_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM puzzle_solving_time gone WHERE gone.id = r.already_yours_solving_time_id)
            )
        )
),
ready AS (
    SELECT
        chosen.*,
        chosen.has_result AND chosen.equal_row = FALSE AND chosen.result_known = FALSE AS new_result,
        chosen.has_owned AND NOT EXISTS (
            SELECT 1 FROM collection_item owned
            WHERE owned.player_id = :playerId AND owned.puzzle_id = chosen.puzzle_id AND owned.collection_id IS NULL
        ) AS new_owned,
        chosen.is_wish
            AND NOT EXISTS (SELECT 1 FROM wish_list_item wished WHERE wished.player_id = :playerId AND wished.puzzle_id = chosen.puzzle_id)
            AND NOT EXISTS (SELECT 1 FROM collection_item owned WHERE owned.player_id = :playerId AND owned.puzzle_id = chosen.puzzle_id)
            AND NOT EXISTS (SELECT 1 FROM puzzle_solving_time solved WHERE solved.player_id = :playerId AND solved.puzzle_id = chosen.puzzle_id)
            AS new_wish
    FROM chosen
    WHERE chosen.choice = 'catalogue'
        AND chosen.puzzle_id IS NOT NULL
        AND (chosen.has_result = FALSE OR chosen.exact_day)
)
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * One statement, whatever the import's size.
     *
     * @throws DataImportNotFound
     */
    public function summary(string $importId, string $playerId): ImportAddSummary
    {
        self::assertIds($importId, $playerId);

        $ready = self::READY_CTE;
        $notAdded = self::NOT_ADDED;

        /** @var false|array{status: string, wiped: bool, paused: bool, rows_ready: int, ready_result_rows: int, results: int, owned: int, wish_list: int, already_yours: int, equal_rows: int, unlisted: int, needs_exact_day: int, undecided: int, need_look: int|string, sheets_not_set_up: int, not_added: int, rows_added: int, results_added: int, collection_items_added: int, wish_list_items_added: int, last_added_at: null|string, running_batch_id: null|string, latest_batch_id: null|string, results_today: int} $row */
        $row = $this->database->executeQuery(<<<SQL
WITH {$ready}
SELECT
    import.status,
    import.cells_wiped_at IS NOT NULL AS wiped,
    COALESCE((SELECT settings.adding_paused FROM data_import_settings settings LIMIT 1), FALSE) AS paused,
    (SELECT COUNT(*) FROM ready) AS rows_ready,
    (SELECT COUNT(*) FROM ready WHERE ready.has_result) AS ready_result_rows,
    (SELECT COUNT(*) FROM ready WHERE ready.new_result) AS results,
    (SELECT COUNT(*) FROM ready WHERE ready.new_owned) AS owned,
    (SELECT COUNT(*) FROM ready WHERE ready.new_wish) AS wish_list,
    (SELECT COUNT(*) FROM ready WHERE ready.new_result = FALSE AND ready.new_owned = FALSE AND ready.new_wish = FALSE) AS already_yours,
    (SELECT COUNT(*) FROM ready WHERE ready.has_result AND ready.equal_row) AS equal_rows,
    (SELECT COUNT(*) FROM chosen WHERE chosen.choice = 'unlisted') AS unlisted,
    (SELECT COUNT(*) FROM chosen WHERE chosen.choice = 'catalogue' AND chosen.puzzle_id IS NOT NULL AND chosen.has_result AND chosen.exact_day = FALSE) AS needs_exact_day,
    (SELECT COUNT(*) FROM chosen WHERE chosen.choice = 'undecided') AS undecided,
    (
        SELECT COALESCE(SUM(sheet.rows_need_look), 0)
        FROM data_import_sheet sheet
        WHERE sheet.import_id = import.id AND sheet.included AND sheet.parsed_version = sheet.mapping_version
    ) AS need_look,
    (
        SELECT COUNT(*)
        FROM data_import_sheet sheet
        WHERE sheet.import_id = import.id AND sheet.included AND sheet.parsed_version IS DISTINCT FROM sheet.mapping_version
    ) AS sheets_not_set_up,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = import.id AND {$notAdded}) AS not_added,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = import.id AND r.state = 'added') AS rows_added,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = import.id AND r.state = 'added' AND r.result_made) AS results_added,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = import.id AND r.state = 'added' AND r.collection_item_id IS NOT NULL) AS collection_items_added,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = import.id AND r.state = 'added' AND r.wish_list_item_id IS NOT NULL) AS wish_list_items_added,
    (SELECT MAX(r.added_at) FROM data_import_row r WHERE r.import_id = import.id AND r.state = 'added') AS last_added_at,
    (
        SELECT batch.id FROM data_import_batch batch
        WHERE batch.import_id = import.id AND batch.state = 'running'
        ORDER BY batch.started_at DESC, batch.id DESC LIMIT 1
    ) AS running_batch_id,
    (
        SELECT batch.id FROM data_import_batch batch
        WHERE batch.import_id = import.id
        ORDER BY batch.started_at DESC, batch.id DESC LIMIT 1
    ) AS latest_batch_id,
    -- What the daily guard counts (DataImportRowRepository::countResultsAddedOrQueuedSince())
    (
        SELECT COUNT(*)
        FROM data_import_row r
        INNER JOIN data_import other ON other.id = r.import_id
        WHERE other.player_id = :playerId
            AND r.kind IN ('result', 'both')
            AND (r.state = 'queued' OR (r.state = 'added' AND r.added_at >= :startOfDay AND r.solving_time_id IS NOT NULL))
    ) AS results_today
FROM import
SQL, [
            'importId' => $importId,
            'playerId' => $playerId,
            'startOfDay' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        return new ImportAddSummary(
            paused: $row['paused'],
            canAdd: in_array($row['status'], [DataImportStatus::SettingUp->value, DataImportStatus::Reviewing->value], true) && $row['wiped'] === false,
            runningBatchId: $row['running_batch_id'],
            latestBatchId: $row['latest_batch_id'],
            rowsReady: $row['rows_ready'],
            readyResultRows: $row['ready_result_rows'],
            results: $row['results'],
            owned: $row['owned'],
            wishList: $row['wish_list'],
            alreadyYours: $row['already_yours'],
            equalRows: $row['equal_rows'],
            resultsLeftToday: max(0, DataImportLimits::MAX_RESULTS_ADDED_PER_DAY - $row['results_today']),
            unlisted: $row['unlisted'],
            needsExactDay: $row['needs_exact_day'],
            undecided: $row['undecided'],
            needLook: (int) $row['need_look'],
            sheetsNotSetUp: $row['sheets_not_set_up'],
            notAdded: $row['not_added'],
            rowsAdded: $row['rows_added'],
            resultsAdded: $row['results_added'],
            collectionItemsAdded: $row['collection_items_added'],
            wishListItemsAdded: $row['wish_list_items_added'],
            lastAddedAt: $row['last_added_at'] === null ? null : new DateTimeImmutable($row['last_added_at']),
        );
    }

    /**
     * Per sheet: how many of its rows are ready. One statement.
     *
     * @return list<array{title: string, ready: int}>
     */
    public function readyBySheet(string $importId, string $playerId): array
    {
        if (self::areIds($importId, $playerId) === false) {
            return [];
        }

        $ready = self::READY_CTE;

        /** @var list<array{title: string, ready: int}> $sheets */
        $sheets = $this->database->executeQuery(<<<SQL
WITH {$ready}
SELECT sheet.title, COUNT(ready.id) AS ready
FROM import
INNER JOIN data_import_sheet sheet ON sheet.import_id = import.id
INNER JOIN ready ON ready.sheet_id = sheet.id
GROUP BY sheet.id, sheet.title, sheet.position
ORDER BY sheet.position
SQL, ['importId' => $importId, 'playerId' => $playerId])->fetchAllAssociative();

        return $sheets;
    }

    /**
     * Ready rows of one kind of the review, in the file's order: `results` (new results - the sample), `known`
     * (rows that add nothing: already the player's), `timed` (results with a time the player has not said "it is
     * right" about - what the time verification is asked about). One statement.
     *
     * @return list<ImportAddRow>
     */
    public function readyRows(string $importId, string $playerId, string $which, int $limit): array
    {
        if (self::areIds($importId, $playerId) === false) {
            return [];
        }

        $condition = match ($which) {
            'results' => 'ready.new_result',
            'known' => 'ready.new_result = FALSE AND ready.new_owned = FALSE AND ready.new_wish = FALSE AND ready.equal_row = FALSE',
            'timed' => <<<SQL
ready.new_result
AND COALESCE(CAST(r.parsed ->> 'seconds' AS INTEGER), 0) > 0
AND NOT (COALESCE(r.answers -> 'addAnyway', '[]'::jsonb) @> '"unusual_time"'::jsonb)
SQL,
            default => 'FALSE',
        };

        return $this->rows(
            'INNER JOIN ready ON ready.id = r.id',
            $condition,
            ['importId' => $importId, 'playerId' => $playerId],
            'sheet.position, r.row_number, r.id',
            $limit,
            0,
            withReady: true,
        );
    }

    /**
     * Rows of the file that are the same result as another ready row - one line per group (the row that stands for
     * it), with how many rows the group has and whether the player said "count each". One statement.
     *
     * @return list<ImportAddRow>
     */
    public function equalGroups(string $importId, string $playerId, int $limit): array
    {
        if (self::areIds($importId, $playerId) === false) {
            return [];
        }

        // A row is "the same as" a row at most two steps away (an equal row of its sheet, then the same row on an
        // earlier sheet) - the group is named by the row that stands for it
        return $this->rows(
            <<<SQL
INNER JOIN (
    SELECT COALESCE(standing.same_as_row_id, equal.same_as_row_id) AS standing_id, COUNT(*) AS equal_rows, BOOL_AND(equal.add_as_separate) AS separate
    FROM data_import_row equal
    INNER JOIN data_import_row standing ON standing.id = equal.same_as_row_id
    WHERE equal.import_id = :importId
        AND equal.kind IN ('result', 'both')
        AND equal.state = 'waiting'
        AND equal.failure IS NULL
        AND equal.problems IS NULL
    GROUP BY COALESCE(standing.same_as_row_id, equal.same_as_row_id)
) equal_group ON equal_group.standing_id = r.id
SQL,
            "r.state = 'waiting' AND r.problems IS NULL AND import_puzzle.choice = 'catalogue'",
            ['importId' => $importId, 'playerId' => $playerId],
            'sheet.position, r.row_number, r.id',
            $limit,
            0,
            equalGroups: true,
        );
    }

    /**
     * The rows of the import with these ids, by id. One statement.
     *
     * @param list<string> $rowIds
     *
     * @return array<string, ImportAddRow>
     */
    public function rowsByIds(string $importId, string $playerId, array $rowIds): array
    {
        $rowIds = array_values(array_filter($rowIds, Uuid::isValid(...)));

        if ($rowIds === [] || self::areIds($importId, $playerId) === false) {
            return [];
        }

        $rows = [];

        foreach ($this->rows('', 'r.id IN (:rowIds)', ['importId' => $importId, 'playerId' => $playerId, 'rowIds' => $rowIds], 'sheet.position, r.row_number, r.id', count($rowIds), 0) as $row) {
            $rows[$row->rowId] = $row;
        }

        return $rows;
    }

    /**
     * A page of the rows the import added and that an Undo can take back - of one "Add", or of all of them.
     *
     * @return list<ImportAddRow>
     */
    public function added(string $importId, string $playerId, null|string $batchId, int $limit, int $offset): array
    {
        return $this->listed("r.state = 'added'", $importId, $playerId, $batchId, 'r.added_at DESC, sheet.position, r.row_number, r.id', $limit, $offset);
    }

    /**
     * A page of the rows an "Add" took and could not add, with the reason code.
     *
     * @return list<ImportAddRow>
     */
    public function notAdded(string $importId, string $playerId, null|string $batchId, int $limit, int $offset): array
    {
        return $this->listed(self::NOT_ADDED, $importId, $playerId, $batchId, 'r.failure, sheet.position, r.row_number, r.id', $limit, $offset);
    }

    /**
     * The rows an Undo came to and left as they are: their result was changed since the import.
     *
     * @return list<ImportAddRow>
     */
    public function keptByUndo(string $importId, string $playerId, string $undoId, int $limit): array
    {
        if (self::areIds($importId, $playerId) === false || Uuid::isValid($undoId) === false) {
            return [];
        }

        return $this->rows(
            '',
            "r.state = 'added' AND r.made ->> 'keptByUndo' = :undoId",
            ['importId' => $importId, 'playerId' => $playerId, 'undoId' => strtolower($undoId)],
            'sheet.position, r.row_number, r.id',
            $limit,
            0,
        );
    }

    /**
     * How many rows the import added / could not add - of one "Add" or of all - and, of the first, what they made:
     * what an Undo of that scope would take back. One statement.
     *
     * @return array{added: int, results: int, collectionItems: int, wishListItems: int, wishListItemsToRestore: int, notAdded: int}
     */
    public function counts(string $importId, string $playerId, null|string $batchId = null, null|string $rowId = null): array
    {
        $none = ['added' => 0, 'results' => 0, 'collectionItems' => 0, 'wishListItems' => 0, 'wishListItemsToRestore' => 0, 'notAdded' => 0];

        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false) || ($rowId !== null && Uuid::isValid($rowId) === false)) {
            return $none;
        }

        $notAdded = self::NOT_ADDED;

        /** @var false|array{added: int, results: int, collection_items: int, wish_list_items: int, wish_list_items_to_restore: int|string, not_added: int} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL) AS added,
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.result_made) AS results,
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.collection_item_id IS NOT NULL) AS collection_items,
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.wish_list_item_id IS NOT NULL) AS wish_list_items,
    COALESCE(SUM(jsonb_array_length(COALESCE(r.made -> 'removedWishListItems', '[]'::jsonb))) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL), 0) AS wish_list_items_to_restore,
    COUNT(*) FILTER (WHERE {$notAdded}) AS not_added
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
    AND (CAST(:batchId AS UUID) IS NULL OR r.batch_id = CAST(:batchId AS UUID))
    AND (CAST(:rowId AS UUID) IS NULL OR r.id = CAST(:rowId AS UUID))
SQL, ['importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId, 'rowId' => $rowId])->fetchAssociative();

        if ($row === false) {
            return $none;
        }

        return [
            'added' => $row['added'],
            'results' => $row['results'],
            'collectionItems' => $row['collection_items'],
            'wishListItems' => $row['wish_list_items'],
            'wishListItemsToRestore' => (int) $row['wish_list_items_to_restore'],
            'notAdded' => $row['not_added'],
        ];
    }

    /**
     * One "Add" of the player's import: what it took, how far it is, what came of it. One statement.
     *
     * @return array{id: string, state: string, running: bool, paused: bool, endedByUndo: bool, startedAt: DateTimeImmutable, finishedAt: null|DateTimeImmutable, undoneAt: null|DateTimeImmutable, rows: int, queued: int, done: int, added: int, alreadyYours: int, notAdded: int, results: int, collectionItems: int, wishListItems: int, stillAdded: int, skipped: array<string, int>}
     *
     * @throws DataImportNotFound
     */
    public function batch(string $importId, string $playerId, string $batchId): array
    {
        self::assertIds($importId, $playerId, $batchId);

        $notAdded = self::NOT_ADDED;

        /** @var false|array{id: string, state: string, paused: bool, ended_by_undo: bool, started_at: string, finished_at: null|string, undone_at: null|string, rows_requested: int, rows_added: int, rows_already_yours: int, rows_refused: int, rows_failed: int, results_added: int, collection_items_added: int, wish_list_items_added: int, skipped: string, queued: int, live_added: int, live_already_yours: int, live_not_added: int, live_results: int, live_collection_items: int, live_wish_list_items: int} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    batch.id,
    batch.state,
    COALESCE((SELECT settings.adding_paused FROM data_import_settings settings LIMIT 1), FALSE) AS paused,
    batch.ended_by_undo,
    batch.started_at,
    batch.finished_at,
    batch.undone_at,
    batch.rows_requested,
    batch.rows_added,
    batch.rows_already_yours,
    batch.rows_refused,
    batch.rows_failed,
    batch.results_added,
    batch.collection_items_added,
    batch.wish_list_items_added,
    batch.skipped,
    COUNT(r.id) FILTER (WHERE r.state = 'queued') AS queued,
    COUNT(r.id) FILTER (WHERE r.state = 'added') AS live_added,
    COUNT(r.id) FILTER (WHERE r.state = 'already_yours') AS live_already_yours,
    COUNT(r.id) FILTER (WHERE {$notAdded}) AS live_not_added,
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.result_made) AS live_results,
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.collection_item_id IS NOT NULL) AS live_collection_items,
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.wish_list_item_id IS NOT NULL) AS live_wish_list_items
FROM data_import_batch batch
INNER JOIN data_import ON data_import.id = batch.import_id
LEFT JOIN data_import_row r ON r.batch_id = batch.id
WHERE batch.id = :batchId
    AND batch.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
GROUP BY batch.id
SQL, ['importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        $running = $row['state'] === 'running';
        $skipped = [];

        foreach ((array) json_decode($row['skipped'], true) as $code => $count) {
            if (is_string($code) && is_int($count) && $count > 0) {
                $skipped[$code] = $count;
            }
        }

        return [
            'id' => $row['id'],
            'state' => $row['state'],
            'running' => $running,
            'paused' => $running && $row['paused'] && $row['ended_by_undo'] === false,
            'endedByUndo' => $row['ended_by_undo'],
            'startedAt' => new DateTimeImmutable($row['started_at']),
            'finishedAt' => $row['finished_at'] === null ? null : new DateTimeImmutable($row['finished_at']),
            'undoneAt' => $row['undone_at'] === null ? null : new DateTimeImmutable($row['undone_at']),
            'rows' => $row['rows_requested'],
            'queued' => $row['queued'],
            'done' => max(0, $row['rows_requested'] - $row['queued']),
            // A finished batch says what it did; a running one what its rows say now
            'added' => $running ? $row['live_added'] : $row['rows_added'],
            'alreadyYours' => $running ? $row['live_already_yours'] : $row['rows_already_yours'],
            'notAdded' => $running ? $row['live_not_added'] : $row['rows_refused'] + $row['rows_failed'],
            'results' => $running ? $row['live_results'] : $row['results_added'],
            'collectionItems' => $running ? $row['live_collection_items'] : $row['collection_items_added'],
            'wishListItems' => $running ? $row['live_wish_list_items'] : $row['wish_list_items_added'],
            // What an Undo of the batch can still take back
            'stillAdded' => $row['live_added'],
            'skipped' => $skipped,
        ];
    }

    /**
     * Why the rows an "Add" took were not added: the reason code and how many rows carry it now. One statement.
     *
     * @return array<string, int>
     */
    public function reasonsOfBatch(string $importId, string $playerId, string $batchId): array
    {
        if (self::areIds($importId, $playerId, $batchId) === false) {
            return [];
        }

        $notAdded = self::NOT_ADDED;

        /** @var array<string, int> $reasons */
        $reasons = $this->database->executeQuery(<<<SQL
SELECT COALESCE(r.failure, 'error') AS code, COUNT(*) AS rows
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.batch_id = :batchId
    AND r.import_id = :importId
    AND data_import.player_id = :playerId
    AND {$notAdded}
GROUP BY COALESCE(r.failure, 'error')
ORDER BY COUNT(*) DESC, COALESCE(r.failure, 'error')
SQL, ['importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId])->fetchAllKeyValue();

        return $reasons;
    }

    /**
     * One Undo of the player's import. One statement.
     *
     * @return array{id: string, scope: string, batchId: null|string, running: bool, byAdmin: bool, startedAt: DateTimeImmutable, finishedAt: null|DateTimeImmutable, rows: int, left: int, done: int, rowsUndone: int, resultsDeleted: int, resultsKeptEdited: int, collectionItemsRemoved: int, collectionItemsKept: int, wishListItemsRemoved: int, wishListItemsRestored: int, rowsFailed: int}
     *
     * @throws DataImportNotFound
     */
    public function undo(string $importId, string $playerId, string $undoId): array
    {
        self::assertIds($importId, $playerId, $undoId);

        /** @var false|array{id: string, scope: string, batch_id: null|string, state: string, by_admin: bool, started_at: string, finished_at: null|string, rows_requested: int, rows_left: int, rows_undone: int, results_deleted: int, results_kept_edited: int, collection_items_removed: int, collection_items_kept: int, wish_list_items_removed: int, wish_list_items_restored: int, rows_failed: int} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    undo.id,
    undo.scope,
    undo.batch_id,
    undo.state,
    undo.by_admin,
    undo.started_at,
    undo.finished_at,
    undo.rows_requested,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.undo_id = undo.id) AS rows_left,
    undo.rows_undone,
    undo.results_deleted,
    undo.results_kept_edited,
    undo.collection_items_removed,
    undo.collection_items_kept,
    undo.wish_list_items_removed,
    undo.wish_list_items_restored,
    undo.rows_failed
FROM data_import_undo undo
INNER JOIN data_import ON data_import.id = undo.import_id
WHERE undo.id = :undoId
    AND undo.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
SQL, ['importId' => $importId, 'playerId' => $playerId, 'undoId' => $undoId])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        return [
            'id' => $row['id'],
            'scope' => $row['scope'],
            'batchId' => $row['batch_id'],
            'running' => $row['state'] === 'running',
            'byAdmin' => $row['by_admin'],
            'startedAt' => new DateTimeImmutable($row['started_at']),
            'finishedAt' => $row['finished_at'] === null ? null : new DateTimeImmutable($row['finished_at']),
            'rows' => $row['rows_requested'],
            'left' => $row['rows_left'],
            'done' => max(0, $row['rows_requested'] - $row['rows_left']),
            'rowsUndone' => $row['rows_undone'],
            'resultsDeleted' => $row['results_deleted'],
            'resultsKeptEdited' => $row['results_kept_edited'],
            'collectionItemsRemoved' => $row['collection_items_removed'],
            'collectionItemsKept' => $row['collection_items_kept'],
            'wishListItemsRemoved' => $row['wish_list_items_removed'],
            'wishListItemsRestored' => $row['wish_list_items_restored'],
            'rowsFailed' => $row['rows_failed'],
        ];
    }

    /**
     * What each of the player's imports added - for Imports home. One statement for all of them.
     *
     * @return array<string, array{results: int, collectionItems: int, wishListItems: int, lastAddedAt: null|DateTimeImmutable, adding: bool}> by import id
     */
    public function addedByImports(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        /** @var list<array{import_id: string, results: int, collection_items: int, wish_list_items: int, last_added_at: null|string, adding: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT
    data_import.id AS import_id,
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.result_made) AS results,
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.collection_item_id IS NOT NULL) AS collection_items,
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.wish_list_item_id IS NOT NULL) AS wish_list_items,
    MAX(r.added_at) FILTER (WHERE r.state = 'added') AS last_added_at,
    EXISTS (SELECT 1 FROM data_import_batch batch WHERE batch.import_id = data_import.id AND batch.state = 'running') AS adding
FROM data_import
INNER JOIN data_import_row r ON r.import_id = data_import.id AND r.batch_id IS NOT NULL
WHERE data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
GROUP BY data_import.id
SQL, ['playerId' => $playerId])->fetchAllAssociative();

        $byImport = [];

        foreach ($rows as $row) {
            $byImport[$row['import_id']] = [
                'results' => $row['results'],
                'collectionItems' => $row['collection_items'],
                'wishListItems' => $row['wish_list_items'],
                'lastAddedAt' => $row['last_added_at'] === null ? null : new DateTimeImmutable($row['last_added_at']),
                'adding' => $row['adding'],
            ];
        }

        return $byImport;
    }

    /**
     * The row's distinct puzzle with the version of its decision as the database has it now - for "forget how this
     * puzzle was paired" (DecideImportPuzzles takes the version the page saw).
     *
     * @return null|array{importPuzzleId: string, version: int, decided: bool}
     */
    public function pairingOfRow(string $importId, string $playerId, string $rowId): null|array
    {
        if (self::areIds($importId, $playerId, $rowId) === false) {
            return null;
        }

        /** @var false|array{id: string, decision_version: int, choice: string} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT import_puzzle.id, import_puzzle.decision_version, import_puzzle.choice
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
INNER JOIN data_import_puzzle import_puzzle ON import_puzzle.id = r.import_puzzle_id
WHERE r.id = :rowId
    AND r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
SQL, ['importId' => $importId, 'playerId' => $playerId, 'rowId' => $rowId])->fetchAssociative();

        return $row === false ? null : ['importPuzzleId' => $row['id'], 'version' => $row['decision_version'], 'decided' => $row['choice'] === 'catalogue'];
    }

    /**
     * @return list<ImportAddRow>
     */
    private function listed(string $condition, string $importId, string $playerId, null|string $batchId, string $order, int $limit, int $offset): array
    {
        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false)) {
            return [];
        }

        $parameters = ['importId' => $importId, 'playerId' => $playerId];

        if ($batchId !== null) {
            $condition .= ' AND r.batch_id = :batchId';
            $parameters['batchId'] = strtolower($batchId);
        }

        return $this->rows('', $condition, $parameters, $order, $limit, $offset);
    }

    /**
     * Rows of the player's import with what they say and the catalogue puzzle they were paired with. One statement.
     *
     * @param array<string, mixed> $parameters importId, playerId and what $join / $condition name
     *
     * @return list<ImportAddRow>
     */
    private function rows(string $join, string $condition, array $parameters, string $order, int $limit, int $offset, bool $withReady = false, bool $equalGroups = false): array
    {
        $with = $withReady ? 'WITH ' . self::READY_CTE : '';
        $equal = $equalGroups ? 'equal_group.equal_rows + 1' : '1';
        $separate = $equalGroups ? 'equal_group.separate' : 'r.add_as_separate';

        /** @var list<array{id: string, row_number: int, kind: null|string, parsed: null|string, title: string, state: string, failure: null|string, import_puzzle_id: null|string, puzzle_id: null|string, puzzle_name: null|string, brand_name: null|string, pieces_count: null|int, image: null|string, image_ratio: null|float|string, solving_time_id: null|string, made_collection_item: bool, made_wish_list_item: bool, added_at: null|string, kept_by_undo: bool, being_taken_back: bool, batch_id: null|string, equal_rows: int, add_as_separate: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
{$with}
SELECT
    r.id,
    r.row_number,
    r.kind,
    r.parsed,
    sheet.title,
    r.state,
    CASE WHEN r.state = 'failed' THEN COALESCE(r.failure, 'error') ELSE r.failure END AS failure,
    r.import_puzzle_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    manufacturer.name AS brand_name,
    puzzle.pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS image_ratio,
    CASE WHEN r.state = 'added' AND r.result_made THEN r.solving_time_id END AS solving_time_id,
    r.state = 'added' AND r.collection_item_id IS NOT NULL AS made_collection_item,
    r.state = 'added' AND r.wish_list_item_id IS NOT NULL AS made_wish_list_item,
    r.added_at,
    r.made ->> 'keptByUndo' IS NOT NULL AS kept_by_undo,
    r.undo_id IS NOT NULL AS being_taken_back,
    r.batch_id,
    {$equal} AS equal_rows,
    {$separate} AS add_as_separate
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id
LEFT JOIN data_import_puzzle import_puzzle ON import_puzzle.id = r.import_puzzle_id
LEFT JOIN puzzle ON puzzle.id = import_puzzle.puzzle_id AND import_puzzle.choice = 'catalogue'
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
{$join}
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
    AND {$condition}
ORDER BY {$order}
LIMIT :limit OFFSET :offset
SQL, [
            ...$parameters,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
            'offset' => $offset,
        ], isset($parameters['rowIds']) ? ['rowIds' => ArrayParameterType::STRING] : [])->fetchAllAssociative();

        return array_map(static fn (array $row): ImportAddRow => new ImportAddRow(
            rowId: $row['id'],
            said: GetImportPuzzleScreen::row([...$row, 'problems' => null]),
            state: $row['state'],
            failure: $row['failure'],
            importPuzzleId: $row['import_puzzle_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            brandName: $row['brand_name'],
            piecesCount: $row['pieces_count'],
            image: $row['image'],
            imageRatio: $row['image_ratio'] === null ? null : (float) $row['image_ratio'],
            solvingTimeId: $row['solving_time_id'],
            madeCollectionItem: $row['made_collection_item'],
            madeWishListItem: $row['made_wish_list_item'],
            addedAt: $row['added_at'] === null ? null : new DateTimeImmutable($row['added_at']),
            keptByUndo: $row['kept_by_undo'],
            beingTakenBack: $row['being_taken_back'],
            batchId: $row['batch_id'],
            equalRows: $row['equal_rows'],
            addAsSeparate: $row['add_as_separate'],
        ), $rows);
    }

    private static function areIds(string ...$ids): bool
    {
        return array_all($ids, Uuid::isValid(...));
    }

    /**
     * @throws DataImportNotFound
     */
    private static function assertIds(string ...$ids): void
    {
        if (self::areIds(...$ids) === false) {
            throw new DataImportNotFound();
        }
    }
}
