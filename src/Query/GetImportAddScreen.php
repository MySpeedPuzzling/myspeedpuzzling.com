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
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Results\ImportAddSummary;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DatePrecision;
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
 * "Ready" is the engine's own definition - the SQL "Add" queues with (DataImportRowRepository::sqlReadyRows()), not
 * a copy of it. What a ready row would make mirrors ImportRowAdder, for showing only: the engine decides again, row
 * by row, under the player's lock. ImportAddScreensTest compares the numbers shown with what the real worker adds.
 */
readonly final class GetImportAddScreen
{
    public const int ROWS_PER_PAGE = 50;
    public const int SAMPLE = 5;
    public const int KNOWN_SHOWN = 20;

    /** The four questions a player may wave through per row (`answers.addAnyway`, adding.md "Refusals") */
    public const array WAVABLE = ['similar_result_exists', 'somebody_elses_result', 'said_already_on_profile', 'unusual_time'];

    /** Refused or threw: a row an "Add" took and did not add */
    private const string NOT_ADDED = "(r.state = 'failed' OR (r.state = 'waiting' AND r.failure IS NOT NULL))";

    /**
     * What each ready row would make, on top of the one definition of "ready" (DataImportRowRepository::
     * sqlReadyRows() - the very CTEs "Add" queues with): `judged` = the ready rows with whether this "Add" takes them
     * (the daily guard) and whether they make a new result, a puzzle for the collection, a wish. What the player
     * already has is read once per statement and asked by a hash (`IN (SELECT …)` in the select list - a hashed
     * sub-plan), the first row per puzzle comes from one sort: no join between two derived sets, whose plan would
     * hang on the planner's guess of their sizes (a nested loop over 50,000 × 15,000 rows was measured - minutes).
     * Several rows that own or wish the same puzzle make one item: the first of them counts.
     *
     * Parameters: importId, playerId, explicit (0), limit (the daily guard), startOfDay.
     */
    private static function sqlJudged(): string
    {
        $ready = DataImportRowRepository::sqlReadyRows();

        return <<<SQL
import AS (
    SELECT data_import.id, data_import.status, data_import.cells_wiped_at
    FROM data_import
    WHERE data_import.id = :importId
        AND data_import.player_id = :playerId
        AND data_import.status <> 'discarded'
),
{$ready},
-- What the daily guard counts (DataImportRowRepository::countResultsAddedOrQueuedSince())
guard AS (
    SELECT GREATEST(0, :limit - COUNT(*)) AS results_left
    FROM data_import_row r
    INNER JOIN data_import other ON other.id = r.import_id
    WHERE other.player_id = :playerId
        AND r.kind IN ('result', 'both')
        AND (
            (r.state = 'queued' AND NOT EXISTS (SELECT 1 FROM data_import_puzzle kept WHERE kept.id = r.import_puzzle_id AND kept.choice = 'unlisted'))
            OR (
                r.solving_time_id IS NOT NULL
                AND (r.result_made_at >= :startOfDay OR (r.result_made_at IS NULL AND r.state = 'added' AND r.added_at >= :startOfDay))
            )
        )
),
judged AS (
    SELECT
        flagged.id,
        flagged.sheet_id,
        flagged.position,
        flagged.row_number,
        flagged.has_result,
        flagged.equal_row,
        flagged.has_result = FALSE OR flagged.choice = 'unlisted' OR flagged.results_so_far <= guard.results_left AS taken,
        -- Kept as the player's own unlisted puzzle: the row makes nothing on the profile
        flagged.choice = 'unlisted' AS unlisted_row,
        flagged.choice <> 'unlisted' AND flagged.has_result AND flagged.equal_row = FALSE AND flagged.known = FALSE AS new_result,
        -- Several rows that own (or wish) the same puzzle make one item: the first of them in the file's order
        flagged.can_own AND SUM(CASE WHEN flagged.can_own THEN 1 ELSE 0 END) OVER by_puzzle = 1 AS new_owned,
        flagged.can_wish AND SUM(CASE WHEN flagged.can_wish THEN 1 ELSE 0 END) OVER by_puzzle = 1 AS new_wish
    FROM (
        -- What the player already has is read once each and asked by a hash - whatever the planner thinks of the
        -- sizes, no row of the file is ever compared with the rows of another set one by one
        SELECT
            ready.*,
            -- The pairing's hint, as the Puzzles summary counts it: the result is there, on the very puzzle
            ready.id IN (
                SELECT hinted.id
                FROM chosen hinted
                INNER JOIN puzzle_solving_time result ON result.id = hinted.already_yours_solving_time_id
                WHERE hinted.state = 'waiting'
                    AND hinted.already_yours_how IS NOT NULL
                    AND (hinted.already_yours_how = 'earlier_import' OR result.puzzle_id = hinted.puzzle_id)
            ) AS known,
            ready.choice <> 'unlisted' AND ready.has_owned AND ready.puzzle_id NOT IN (
                SELECT item.puzzle_id FROM collection_item item WHERE item.player_id = :playerId AND item.collection_id IS NULL
            ) AS can_own,
            ready.choice <> 'unlisted' AND ready.is_wish
                AND ready.puzzle_id NOT IN (SELECT wish.puzzle_id FROM wish_list_item wish WHERE wish.player_id = :playerId)
                AND ready.puzzle_id NOT IN (SELECT item.puzzle_id FROM collection_item item WHERE item.player_id = :playerId)
                AND ready.puzzle_id NOT IN (SELECT result.puzzle_id FROM puzzle_solving_time result WHERE result.player_id = :playerId)
                AS can_wish
        FROM ready
    ) flagged
    CROSS JOIN guard
    WINDOW by_puzzle AS (PARTITION BY flagged.puzzle_id ORDER BY flagged.position, flagged.row_number, flagged.id)
)
SQL;
    }

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * One statement, whatever the import's size: the ready set is built once, every number comes from one pass over
     * it. With $forReview also the review's own parts - the ready rows per sheet and which rows it shows first (the
     * first new results, the first rows already the player's) - so the review builds nothing a second time.
     *
     * @throws DataImportNotFound
     */
    public function summary(string $importId, string $playerId, bool $forReview = false): ImportAddSummary
    {
        self::assertIds($importId, $playerId);

        $judged = self::sqlJudged();
        $notAdded = self::NOT_ADDED;
        $review = $forReview ? <<<SQL
(
    -- A line per sheet, with what its results will be added as - solo, pair, team - and with whom: a sheet read as
    -- the wrong kind, or a sheet of somebody else's results, is seen before the tap (people.md "The review's line")
    SELECT jsonb_agg(jsonb_build_object(
        'title', sheet.title,
        'ready', per_sheet.rows,
        'results', per_sheet.results,
        'solo', per_sheet.solo,
        'duo', per_sheet.duo,
        'team', per_sheet.team,
        'people', (
            SELECT jsonb_agg(first_named.person)
            FROM (
                SELECT DISTINCT named.person
                FROM judged named_row
                INNER JOIN data_import_row named_said ON named_said.id = named_row.id
                CROSS JOIN LATERAL jsonb_array_elements_text(
                    CASE WHEN jsonb_typeof(named_said.parsed -> 'people') = 'array' THEN named_said.parsed -> 'people' ELSE '[]'::jsonb END
                ) AS named(person)
                WHERE named_row.taken AND named_row.sheet_id = per_sheet.sheet_id AND named_said.kind IN ('result', 'both')
                ORDER BY named.person
                LIMIT 4
            ) first_named
        )
    ) ORDER BY sheet.position)
    FROM (
        SELECT
            judged.sheet_id,
            COUNT(*) AS rows,
            COUNT(*) FILTER (WHERE said.kind IN ('result', 'both')) AS results,
            COUNT(*) FILTER (WHERE said.kind IN ('result', 'both') AND said.parsed ->> 'type' = 'solo') AS solo,
            COUNT(*) FILTER (WHERE said.kind IN ('result', 'both') AND said.parsed ->> 'type' = 'duo') AS duo,
            COUNT(*) FILTER (WHERE said.kind IN ('result', 'both') AND said.parsed ->> 'type' = 'team') AS team
        FROM judged
        INNER JOIN data_import_row said ON said.id = judged.id
        WHERE judged.taken
        GROUP BY judged.sheet_id
    ) per_sheet
    INNER JOIN data_import_sheet sheet ON sheet.id = per_sheet.sheet_id
) AS sheets,
(
    SELECT jsonb_agg(first.id)
    FROM (SELECT judged.id FROM judged WHERE judged.taken AND judged.new_result ORDER BY judged.position, judged.row_number, judged.id LIMIT :sample) first
) AS sample_ids,
(
    SELECT jsonb_agg(first.id)
    FROM (
        SELECT judged.id FROM judged
        WHERE judged.taken AND judged.unlisted_row = FALSE AND judged.new_result = FALSE AND judged.new_owned = FALSE AND judged.new_wish = FALSE AND judged.equal_row = FALSE
        ORDER BY judged.position, judged.row_number, judged.id LIMIT :known
    ) first
) AS known_ids
SQL : 'NULL AS sheets, NULL AS sample_ids, NULL AS known_ids';

        /** @var false|array{status: string, wiped: bool, paused: bool, rows_ready: int, rows_taken: int, results: int, owned: int, wish_list: int, later: int, already_yours: int, equal_rows: int, results_left: int, unlisted: int, needs_exact_day: int, undecided: int, need_look: int|string, sheets_not_set_up: int, not_added: int, rows_added: int, results_added: int, collection_items_added: int, wish_list_items_added: int, last_added_at: null|string, running_batch_id: null|string, latest_batch_id: null|string, sheets: null|string, sample_ids: null|string, known_ids: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
WITH {$judged},
counts AS (
    SELECT
        COUNT(*) AS rows_ready,
        COUNT(*) FILTER (WHERE judged.taken) AS rows_taken,
        COUNT(*) FILTER (WHERE judged.taken AND judged.new_result) AS results,
        COUNT(*) FILTER (WHERE judged.taken AND judged.new_owned) AS owned,
        COUNT(*) FILTER (WHERE judged.taken AND judged.new_wish) AS wish_list,
        -- What waits for tomorrow: the results over today's guard (and the puzzle of a row that is both)
        COUNT(*) FILTER (WHERE judged.taken = FALSE AND judged.new_result) + COUNT(*) FILTER (WHERE judged.taken = FALSE AND judged.new_owned) AS later,
        COUNT(*) FILTER (WHERE judged.taken AND judged.unlisted_row = FALSE AND judged.new_result = FALSE AND judged.new_owned = FALSE AND judged.new_wish = FALSE) AS already_yours,
        -- Rows kept with the player's unlisted puzzles - only they see them
        COUNT(*) FILTER (WHERE judged.taken AND judged.unlisted_row) AS unlisted,
        COUNT(*) FILTER (WHERE judged.taken AND judged.has_result AND judged.equal_row) AS equal_rows
    FROM judged
),
waiting AS (
    SELECT
        COUNT(*) FILTER (WHERE chosen.choice = 'catalogue' AND chosen.puzzle_id IS NOT NULL AND chosen.has_result AND chosen.exact_day = FALSE) AS needs_exact_day,
        COUNT(*) FILTER (WHERE chosen.choice = 'undecided') AS undecided
    FROM chosen
),
-- The rows an "Add" ever took: what they made, and the ones that could not be added
taken_before AS (
    SELECT
        COUNT(*) FILTER (WHERE {$notAdded}) AS not_added,
        COUNT(*) FILTER (WHERE r.state = 'added') AS rows_added,
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.result_made) AS results_added,
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.collection_item_id IS NOT NULL) AS collection_items_added,
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.wish_list_item_id IS NOT NULL) AS wish_list_items_added,
        MAX(r.added_at) FILTER (WHERE r.state = 'added') AS last_added_at
    FROM data_import_row r
    WHERE r.import_id = :importId AND r.batch_id IS NOT NULL
)
SELECT
    import.status,
    import.cells_wiped_at IS NOT NULL AS wiped,
    COALESCE((SELECT settings.adding_paused FROM data_import_settings settings LIMIT 1), FALSE) AS paused,
    counts.*,
    guard.results_left,
    waiting.*,
    taken_before.*,
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
    {$review}
FROM import
CROSS JOIN counts
CROSS JOIN guard
CROSS JOIN waiting
CROSS JOIN taken_before
SQL, [
            'importId' => $importId,
            'playerId' => $playerId,
            'explicit' => 0,
            'limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY,
            'startOfDay' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->format('Y-m-d H:i:s'),
            ...($forReview ? ['sample' => self::SAMPLE, 'known' => self::KNOWN_SHOWN] : []),
        ])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        $sheets = [];

        foreach (self::decoded($row['sheets']) as $sheet) {
            if (is_array($sheet) && is_string($sheet['title'] ?? null) && is_int($sheet['ready'] ?? null)) {
                $sheets[] = [
                    'title' => $sheet['title'],
                    'ready' => $sheet['ready'],
                    'results' => is_int($sheet['results'] ?? null) ? $sheet['results'] : 0,
                    'solo' => is_int($sheet['solo'] ?? null) ? $sheet['solo'] : 0,
                    'duo' => is_int($sheet['duo'] ?? null) ? $sheet['duo'] : 0,
                    'team' => is_int($sheet['team'] ?? null) ? $sheet['team'] : 0,
                    'people' => array_values(array_filter(is_array($sheet['people'] ?? null) ? $sheet['people'] : [], is_string(...))),
                ];
            }
        }

        return new ImportAddSummary(
            paused: $row['paused'],
            canAdd: in_array($row['status'], [DataImportStatus::SettingUp->value, DataImportStatus::Reviewing->value], true) && $row['wiped'] === false,
            runningBatchId: $row['running_batch_id'],
            latestBatchId: $row['latest_batch_id'],
            rowsReady: $row['rows_ready'],
            rowsTaken: $row['rows_taken'],
            results: $row['results'],
            owned: $row['owned'],
            wishList: $row['wish_list'],
            later: $row['later'],
            alreadyYours: $row['already_yours'],
            equalRows: $row['equal_rows'],
            resultsLeftToday: $row['results_left'],
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
            sheets: $sheets,
            sampleIds: array_values(array_filter(self::decoded($row['sample_ids']), is_string(...))),
            knownIds: array_values(array_filter(self::decoded($row['known_ids']), is_string(...))),
            wiped: $row['wiped'],
        );
    }

    /**
     * The ready results with a time the player has not said "it is right" about - what the time verification is
     * asked about (ImportAddTimesController), in the file's order. One statement.
     *
     * @return list<ImportAddRow>
     */
    public function timedRows(string $importId, string $playerId, int $limit): array
    {
        if (self::areIds($importId, $playerId) === false) {
            return [];
        }

        return $this->rows(
            'INNER JOIN ready ON ready.id = r.id',
            <<<SQL
ready.has_result
AND ready.choice = 'catalogue'
AND ready.equal_row = FALSE
AND COALESCE(CAST(r.parsed ->> 'seconds' AS INTEGER), 0) > 0
AND r.answers -> 'addAnyway' ->> 'unusual_time' IS NULL
SQL,
            ['importId' => $importId, 'playerId' => $playerId, 'explicit' => 0],
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
     * @return array{id: string, state: string, running: bool, paused: bool, endedByUndo: bool, startedAt: DateTimeImmutable, finishedAt: null|DateTimeImmutable, undoneAt: null|DateTimeImmutable, rows: int, queued: int, done: int, added: int, alreadyYours: int, notAdded: int, results: int, collectionItems: int, wishListItems: int, stillAdded: int, unlisted: int, skipped: array<string, int>}
     *
     * @throws DataImportNotFound
     */
    public function batch(string $importId, string $playerId, string $batchId): array
    {
        self::assertIds($importId, $playerId, $batchId);

        $notAdded = self::NOT_ADDED;

        /** @var false|array{id: string, state: string, paused: bool, ended_by_undo: bool, started_at: string, finished_at: null|string, undone_at: null|string, rows_requested: int, rows_added: int, rows_already_yours: int, rows_refused: int, rows_failed: int, results_added: int, collection_items_added: int, wish_list_items_added: int, skipped: string, queued: int, live_added: int, live_already_yours: int, live_not_added: int, live_results: int, live_collection_items: int, live_wish_list_items: int, live_unlisted: int} $row */
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
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.wish_list_item_id IS NOT NULL) AS live_wish_list_items,
    -- Rows kept with the player's unlisted puzzles (only the row's own record is read - never the unlisted tables)
    COUNT(r.id) FILTER (WHERE r.state = 'added' AND r.made -> 'unlisted' IS NOT NULL) AS live_unlisted
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
            // Rows that are kept with the player's unlisted puzzles
            'unlisted' => $row['live_unlisted'],
            'skipped' => $skipped,
        ];
    }

    /**
     * How far one "Add" is - what its page asks every few seconds: the batch's own row and the number of its rows
     * that still wait for their turn (an index range on `(batch_id, state)` that shrinks as the batch goes on) -
     * never an aggregate over all the batch's rows.
     *
     * @return array{id: string, running: bool, paused: bool, total: int, done: int}
     *
     * @throws DataImportNotFound
     */
    public function batchProgress(string $importId, string $playerId, string $batchId): array
    {
        self::assertIds($importId, $playerId, $batchId);

        /** @var false|array{id: string, state: string, paused: bool, ended_by_undo: bool, rows_requested: int, queued: int} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    batch.id,
    batch.state,
    COALESCE((SELECT settings.adding_paused FROM data_import_settings settings LIMIT 1), FALSE) AS paused,
    batch.ended_by_undo,
    batch.rows_requested,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.batch_id = batch.id AND r.state = 'queued') AS queued
FROM data_import_batch batch
INNER JOIN data_import ON data_import.id = batch.import_id
WHERE batch.id = :batchId
    AND batch.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
SQL, ['importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        $running = $row['state'] === 'running';

        return [
            'id' => $row['id'],
            'running' => $running,
            'paused' => $running && $row['paused'] && $row['ended_by_undo'] === false,
            'total' => $row['rows_requested'],
            'done' => max(0, $row['rows_requested'] - $row['queued']),
        ];
    }

    /**
     * What became of the rows an "Add" took and did not add anything for, as they stand now: why rows could not be
     * added (the reason code) and how rows were already the player's (`profile`, `earlier_import`, `this_row`,
     * `equal_row`, `owned`) - each with its number of rows. One statement.
     *
     * @return array{reasons: array<string, int>, alreadyYours: array<string, int>}
     */
    public function outcomesOfBatch(string $importId, string $playerId, string $batchId): array
    {
        $outcomes = ['reasons' => [], 'alreadyYours' => []];

        if (self::areIds($importId, $playerId, $batchId) === false) {
            return $outcomes;
        }

        $notAdded = self::NOT_ADDED;

        /** @var list<array{outcome: string, code: string, rows: int}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT
    CASE WHEN r.state = 'already_yours' THEN 'alreadyYours' ELSE 'reasons' END AS outcome,
    CASE WHEN r.state = 'already_yours' THEN COALESCE(r.already_yours_how, 'owned') ELSE COALESCE(r.failure, 'error') END AS code,
    COUNT(*) AS rows
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.batch_id = :batchId
    AND r.import_id = :importId
    AND data_import.player_id = :playerId
    AND (r.state = 'already_yours' OR {$notAdded})
GROUP BY 1, 2
ORDER BY COUNT(*) DESC, 2
SQL, ['importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId])->fetchAllAssociative();

        foreach ($rows as $row) {
            $outcomes[$row['outcome'] === 'alreadyYours' ? 'alreadyYours' : 'reasons'][$row['code']] = $row['rows'];
        }

        return $outcomes;
    }

    /**
     * The rows of the import (of one "Add", or of all) that could not be added for one reason - the ids one answer
     * for all of them is given to.
     *
     * @return list<string>
     */
    public function rowIdsNotAddedFor(string $importId, string $playerId, string $code, null|string $batchId, int $limit): array
    {
        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false)) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(<<<SQL
SELECT r.id
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
    AND r.state = 'waiting'
    AND r.failure = :code
    AND (CAST(:batchId AS UUID) IS NULL OR r.batch_id = CAST(:batchId AS UUID))
ORDER BY sheet.position, r.row_number, r.id
LIMIT :limit
SQL, ['importId' => $importId, 'playerId' => $playerId, 'code' => $code, 'batchId' => $batchId, 'limit' => $limit])->fetchFirstColumn();

        return $ids;
    }

    /**
     * For rows that wait with "a similar result exists" or "somebody else's result": the results the question is
     * about, to be shown beside the row before "Add it anyway" - time, day and kind only. A similar result is the
     * player's own (suspicious or not - it is theirs, and shown to nobody else); somebody else's is a pair/team
     * result of the puzzle with the row's very day and time, shown without anybody's name. One statement.
     *
     * @param list<string> $rowIds
     *
     * @return array<string, list<array{seconds: null|int, day: DateTimeImmutable, precision: DatePrecision, type: string}>> by row id
     */
    public function resultsBeside(string $importId, string $playerId, array $rowIds): array
    {
        $rowIds = array_values(array_filter($rowIds, Uuid::isValid(...)));

        if ($rowIds === [] || self::areIds($importId, $playerId) === false) {
            return [];
        }

        $rowDay = DataImportRowRepository::sqlRowAnchorDay('r');
        // The coarser of what the row and the result know of their day: 0 a day, 1 a month, 2 a year
        $coarser = <<<SQL
GREATEST(
    CASE r.parsed ->> 'datePrecision' WHEN 'year' THEN 2 WHEN 'month' THEN 1 ELSE 0 END,
    CASE WHEN result.finished_at IS NULL THEN 0 WHEN result.finished_at_precision = 'year' THEN 2 WHEN result.finished_at_precision = 'month' THEN 1 ELSE 0 END
)
SQL;
        $samePeriod = <<<SQL
DATE_TRUNC(CASE {$coarser} WHEN 2 THEN 'year' WHEN 1 THEN 'month' ELSE 'day' END, COALESCE(result.finished_at, result.tracked_at))
    = DATE_TRUNC(CASE {$coarser} WHEN 2 THEN 'year' WHEN 1 THEN 'month' ELSE 'day' END, CAST({$rowDay} AS TIMESTAMP))
SQL;

        // The rules are ImportNeverTwice::similar() and somebodyElseHasIt(): two exact days as ever; where the row or
        // the result knows only a month or a year, the same time (never the stored first day against a real day)
        /** @var list<array{row_id: string, seconds_to_solve: null|int, day: string, precision: null|string, puzzling_type: string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT r.id AS row_id, beside.seconds_to_solve, beside.day, beside.precision, beside.puzzling_type
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
INNER JOIN data_import_puzzle import_puzzle ON import_puzzle.id = r.import_puzzle_id
CROSS JOIN LATERAL (
    SELECT
        result.seconds_to_solve,
        CAST(COALESCE(result.finished_at, result.tracked_at) AS DATE) AS day,
        CASE WHEN result.finished_at IS NULL THEN NULL ELSE result.finished_at_precision END AS precision,
        result.puzzling_type
    FROM puzzle_solving_time result
    WHERE result.puzzle_id = import_puzzle.puzzle_id
        AND (
            (
                r.failure = 'similar_result_exists'
                AND result.player_id = :playerId
                AND (
                    (
                        {$coarser} = 0
                        AND (result.seconds_to_solve = CAST(r.parsed ->> 'seconds' AS INTEGER)) <> (CAST(COALESCE(result.finished_at, result.tracked_at) AS DATE) = {$rowDay})
                    )
                    OR ({$coarser} > 0 AND result.seconds_to_solve = CAST(r.parsed ->> 'seconds' AS INTEGER))
                )
            )
            OR (
                r.failure = 'somebody_elses_result'
                AND result.player_id IS NOT NULL
                AND result.player_id <> :playerId
                AND result.puzzling_type <> 'solo'
                AND result.seconds_to_solve = CAST(r.parsed ->> 'seconds' AS INTEGER)
                AND {$samePeriod}
            )
        )
    ORDER BY COALESCE(result.finished_at, result.tracked_at), result.id
    LIMIT 3
) beside
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND r.id IN (:rowIds)
    AND r.failure IN ('similar_result_exists', 'somebody_elses_result')
    AND {$rowDay} IS NOT NULL
SQL, ['importId' => $importId, 'playerId' => $playerId, 'rowIds' => $rowIds], ['rowIds' => ArrayParameterType::STRING])->fetchAllAssociative();

        $beside = [];

        foreach ($rows as $row) {
            $beside[$row['row_id']][] = [
                'seconds' => $row['seconds_to_solve'],
                'day' => new DateTimeImmutable($row['day']),
                'precision' => DatePrecision::ofStored($row['precision']),
                'type' => $row['puzzling_type'],
            ];
        }

        return $beside;
    }

    /**
     * One group answer of the player's import (DataImportGroupAnswer): what it is about, how far it is, what came
     * of it. One statement.
     *
     * @return array{id: string, code: string, action: string, beforeAdd: bool, ofBatchId: null|string, running: bool, stale: bool, total: int, done: int, answered: int, batchId: null|string, addStatus: null|string}
     *
     * @throws DataImportNotFound
     */
    public function groupAnswer(string $importId, string $playerId, string $answerId): array
    {
        self::assertIds($importId, $playerId, $answerId);

        /** @var false|array{id: string, code: string, action: string, before_add: bool, of_batch_id: null|string, state: string, last_activity_at: string, rows_total: int, rows_done: int, rows_answered: int, batch_id: null|string, add_status: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT answer.id, answer.code, answer.action, answer.before_add, answer.of_batch_id, answer.state, answer.last_activity_at,
    answer.rows_total, answer.rows_done, answer.rows_answered, answer.batch_id, answer.add_status
FROM data_import_group_answer answer
INNER JOIN data_import ON data_import.id = answer.import_id
WHERE answer.id = :answerId
    AND answer.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
SQL, ['importId' => $importId, 'playerId' => $playerId, 'answerId' => $answerId])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        $running = $row['state'] === 'running';

        return [
            'id' => $row['id'],
            'code' => $row['code'],
            'action' => $row['action'],
            'beforeAdd' => $row['before_add'],
            'ofBatchId' => $row['of_batch_id'],
            'running' => $running,
            // No turn gave a sign of life for a minute: its page offers to go on (a worker restarted, a job lost)
            'stale' => $running && new DateTimeImmutable($row['last_activity_at']) < $this->clock->now()->modify('-60 seconds'),
            'total' => $row['rows_total'],
            'done' => min($row['rows_total'], $row['rows_done']),
            'answered' => $row['rows_answered'],
            'batchId' => $row['batch_id'],
            'addStatus' => $row['add_status'],
        ];
    }

    /**
     * How many rows of the import (of one "Add", or of all) wait with each reason - what the group cards say - and
     * how many results with a time the import has at all (what "most of the times in this file" is measured by).
     * One statement.
     *
     * @return array{reasons: array<string, int>, timedResults: int}
     */
    public function notAddedByReason(string $importId, string $playerId, null|string $batchId): array
    {
        $none = ['reasons' => [], 'timedResults' => 0];

        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false)) {
            return $none;
        }

        /** @var list<array{code: null|string, rows: int}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT r.failure AS code, COUNT(*) AS rows
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
    AND r.state = 'waiting'
    AND r.failure IS NOT NULL
    AND (CAST(:batchId AS UUID) IS NULL OR r.batch_id = CAST(:batchId AS UUID))
GROUP BY r.failure

UNION ALL

SELECT NULL, COUNT(*)
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND r.kind IN ('result', 'both')
    AND r.state IN ('waiting', 'queued', 'added', 'already_yours')
    AND COALESCE(CAST(r.parsed ->> 'seconds' AS INTEGER), 0) > 0
SQL, ['importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId])->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($row['code'] === null) {
                $none['timedResults'] = $row['rows'];
            } else {
                $none['reasons'][$row['code']] = $row['rows'];
            }
        }

        return $none;
    }

    /**
     * Some of the rows that wait with one reason, picked evenly from the whole group (not the first of the file) -
     * what a summary of the group is worked out from.
     *
     * @return list<string>
     */
    public function sampleNotAddedFor(string $importId, string $playerId, string $code, null|string $batchId, int $limit): array
    {
        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false)) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(<<<SQL
SELECT r.id
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
    AND r.state = 'waiting'
    AND r.failure = :code
    AND (CAST(:batchId AS UUID) IS NULL OR r.batch_id = CAST(:batchId AS UUID))
ORDER BY md5(CAST(r.id AS TEXT))
LIMIT :limit
SQL, ['importId' => $importId, 'playerId' => $playerId, 'code' => $code, 'batchId' => $batchId, 'limit' => $limit])->fetchFirstColumn();

        return $ids;
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
        $with = $withReady ? 'WITH ' . DataImportRowRepository::sqlReadyRows() : '';
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

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(null|string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
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
