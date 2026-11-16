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
use SpeedPuzzling\Web\Results\ImportAddListRow;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Results\ImportAddSheetLine;
use SpeedPuzzling\Web\Results\ImportAddSummary;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetReadings;
use SpeedPuzzling\Web\Services\DataImport\StoredCells;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
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
    -- Rows queued for the profile now, plus the results made today - the day's count, which an Undo never lowers
    -- (or, for results made before that count existed, the rows that still name them: whichever is more)
    SELECT GREATEST(0, :limit
        - COUNT(*) FILTER (WHERE r.state = 'queued' AND NOT EXISTS (SELECT 1 FROM data_import_puzzle kept WHERE kept.id = r.import_puzzle_id AND kept.choice = 'unlisted'))
        - GREATEST(
            COUNT(*) FILTER (
                WHERE r.solving_time_id IS NOT NULL
                    AND (r.result_made_at >= :startOfDay OR (r.result_made_at IS NULL AND r.state = 'added' AND r.added_at >= :startOfDay))
            ),
            COALESCE((
                SELECT SUM(made.results) FROM data_import_daily_count made
                WHERE made.importer_id = :playerId AND made.partner_id = :playerId AND made.day = CAST(:startOfDay AS DATE)
            ), 0)
        )
    ) AS results_left
    FROM data_import_row r
    INNER JOIN data_import other ON other.id = r.import_id
    WHERE other.player_id = :playerId
        AND r.kind IN ('result', 'both')
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
    -- A line per sheet, with what its rows will be added as - results (solo, pair, team - and with whom), puzzles the
    -- player owns, wishes -, how its values are read and one row of it as the example: a sheet read as the wrong
    -- kind, or a sheet of somebody else's results, is seen before the tap (guided-flow.md "How we read your file")
    SELECT jsonb_agg(jsonb_build_object(
        'id', sheet.id,
        'title', sheet.title,
        'mappingVersion', sheet.mapping_version,
        'options', sheet.options,
        'columns', sheet.mapping -> 'columns',
        'ready', per_sheet.rows,
        'results', per_sheet.results,
        'solo', per_sheet.solo,
        'duo', per_sheet.duo,
        'team', per_sheet.team,
        'owned', per_sheet.owned,
        'both', per_sheet.both_rows,
        'wish', per_sheet.wish,
        'coarse', per_sheet.coarse,
        'exampleId', example.id,
        'exampleCells', example.cells,
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
            COUNT(*) FILTER (WHERE said.kind IN ('result', 'both') AND said.parsed ->> 'type' = 'team') AS team,
            COUNT(*) FILTER (WHERE said.kind IN ('owned', 'both')) AS owned,
            COUNT(*) FILTER (WHERE said.kind = 'both') AS both_rows,
            COUNT(*) FILTER (WHERE said.kind = 'wish_list') AS wish,
            -- A day known to the month or the year only (date-precision.md)
            COUNT(*) FILTER (WHERE said.kind IN ('result', 'both') AND said.parsed ->> 'datePrecision' IN ('month', 'year')) AS coarse,
            -- The sheet's example: its first row that makes a new result - else its first row
            COALESCE(MIN(judged.row_number) FILTER (WHERE judged.new_result), MIN(judged.row_number)) AS example_row
        FROM judged
        INNER JOIN data_import_row said ON said.id = judged.id
        WHERE judged.taken
        GROUP BY judged.sheet_id
    ) per_sheet
    INNER JOIN data_import_sheet sheet ON sheet.id = per_sheet.sheet_id
    LEFT JOIN LATERAL (
        SELECT first_row.id, first_row.cells
        FROM data_import_row first_row
        WHERE first_row.sheet_id = per_sheet.sheet_id AND first_row.row_number = per_sheet.example_row
        ORDER BY first_row.id
        LIMIT 1
    ) example ON TRUE
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
            ...$this->judgedParameters($importId, $playerId),
            ...($forReview ? ['sample' => self::SAMPLE, 'known' => self::KNOWN_SHOWN] : []),
        ])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        $sheets = [];

        foreach (self::decoded($row['sheets']) as $sheet) {
            if (is_array($sheet) === false || is_string($sheet['id'] ?? null) === false || is_string($sheet['title'] ?? null) === false || is_int($sheet['ready'] ?? null) === false) {
                continue;
            }

            $number = static fn (string $key): int => is_int($sheet[$key] ?? null) ? $sheet[$key] : 0;
            $cells = $sheet['exampleCells'] ?? null;

            $sheets[] = new ImportAddSheetLine(
                sheetId: $sheet['id'],
                title: $sheet['title'],
                mappingVersion: $number('mappingVersion'),
                ready: $sheet['ready'],
                results: $number('results'),
                solo: $number('solo'),
                duo: $number('duo'),
                team: $number('team'),
                owned: $number('owned'),
                both: $number('both'),
                wish: $number('wish'),
                coarseDates: $number('coarse'),
                people: array_values(array_filter(is_array($sheet['people'] ?? null) ? $sheet['people'] : [], is_string(...))),
                readings: ImportSheetReadings::of(is_array($sheet['options'] ?? null) ? $sheet['options'] : null),
                exampleId: is_string($sheet['exampleId'] ?? null) ? $sheet['exampleId'] : null,
                exampleSaid: ImportSheetReadings::said(['columns' => $sheet['columns'] ?? null], is_array($cells) ? $cells : null),
            );
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
     * **"What will be added"** (docs/features/data-import/guided-flow.md §7): a page of the rows an "Add" would take
     * right now - `judged.taken`, on top of the one definition of "ready" (DataImportRowRepository::sqlReadyRows()),
     * so the list *is* what "Add" queues, never a second rule (ImportAddListTest compares the two) - each with what
     * it would make, in the file's order (the order the "Add" takes them in). And, in their place, the rows the
     * player said "Don't import" about (`left_out` by the player - AnswerImportRows): one tap puts them back.
     *
     * One statement, whatever the import's size; `total` counts every row of the selection, `taken` the ones an "Add"
     * would take.
     *
     * @param null|string $makes `results`, `collection`, `wish` - only the rows that make one; a row that is not
     *        imported says nothing of what it would make and is listed without this filter only
     * @param string $search the player's own text: a part of the puzzle's name - the catalogue's or the file's
     *
     * @return array{rows: list<ImportAddListRow>, total: int, taken: int}
     */
    public function readyRows(string $importId, string $playerId, null|string $makes, null|string $sheetId, string $search, int $limit, int $offset): array
    {
        $none = ['rows' => [], 'total' => 0, 'taken' => 0];

        if (self::areIds($importId, $playerId) === false || ($sheetId !== null && Uuid::isValid($sheetId) === false)) {
            return $none;
        }

        $judged = self::sqlJudged();
        $now = $this->clock->now();
        $parameters = [...$this->judgedParameters($importId, $playerId), 'now' => $now->format('Y-m-d H:i:s'), 'rowLimit' => $limit, 'rowOffset' => max(0, $offset)];
        $conditions = [];

        $makesColumn = match ($makes) {
            'results' => 'listed.new_result',
            'collection' => 'listed.new_owned',
            'wish' => 'listed.new_wish',
            default => null,
        };

        if ($makesColumn !== null) {
            $conditions[] = $makesColumn;
        }

        if ($sheetId !== null) {
            $conditions[] = 'listed.sheet_id = :sheetId';
            $parameters['sheetId'] = strtolower($sheetId);
        }

        $search = trim(mb_substr($search, 0, 100));
        $textSearch = PuzzleTextSearch::fromUserInput($search);

        if ($search !== '') {
            // The file's own name by the text as typed; the catalogue's names through the one puzzle text search. A
            // row that is not imported kept only its cells: whatever one of them says
            $catalogue = $textSearch->isEmpty() ? 'FALSE' : '(puzzle.id IS NOT NULL AND ' . $textSearch->condition('puzzle') . ')';
            $conditions[] = "(LOWER(r.parsed ->> 'name') LIKE :needle OR {$catalogue} OR (listed.not_imported AND LOWER(CAST(r.cells AS TEXT)) LIKE :needle))";
            $parameters = [...$parameters, ...$textSearch->parameters($now), 'needle' => '%' . addcslashes(mb_strtolower($search), '%_\\') . '%'];
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        /** @var list<array{id: string, row_number: int, kind: null|string, parsed: null|string, title: string, state: string, failure: null|string, import_puzzle_id: null|string, puzzle_id: null|string, puzzle_name: null|string, brand_name: null|string, pieces_count: null|int, image: null|string, image_ratio: null|float|string, solving_time_id: null|string, made_collection_item: bool, made_wish_list_item: bool, added_at: null|string, kept_by_undo: bool, being_taken_back: bool, batch_id: null|string, equal_rows: int, add_as_separate: bool, sheet_id: string, not_imported: bool, new_result: bool, new_owned: bool, new_wish: bool, unlisted_row: bool, equal_row: bool, file_says: null|string, total: int, taken: int}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
WITH {$judged},
listed AS (
    SELECT
        judged.id, judged.sheet_id, judged.position, judged.row_number, FALSE AS not_imported,
        judged.new_result, judged.new_owned, judged.new_wish, judged.unlisted_row, judged.has_result AND judged.equal_row AS equal_row
    FROM judged
    -- The player's own import only: `import` is empty for anybody else's
    CROSS JOIN import
    WHERE judged.taken

    UNION ALL

    -- The rows the player said "Don't import" about, in their place in the file (they kept nothing but their cells)
    SELECT left_out.id, left_out.sheet_id, sheet.position, left_out.row_number, TRUE, FALSE, FALSE, FALSE, FALSE, FALSE
    FROM data_import_row left_out
    INNER JOIN import ON import.id = left_out.import_id
    INNER JOIN data_import_sheet sheet ON sheet.id = left_out.sheet_id
    WHERE sheet.included
        AND left_out.state = 'left_out'
        AND left_out.parsed ->> 'leftOut' = 'by_player'
        AND :selectsMakes = 0
)
SELECT
    r.id,
    r.row_number,
    r.kind,
    r.parsed,
    sheet.title,
    r.state,
    r.failure,
    r.import_puzzle_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    manufacturer.name AS brand_name,
    puzzle.pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS image_ratio,
    NULL AS solving_time_id,
    FALSE AS made_collection_item,
    FALSE AS made_wish_list_item,
    NULL AS added_at,
    FALSE AS kept_by_undo,
    FALSE AS being_taken_back,
    NULL AS batch_id,
    1 AS equal_rows,
    r.add_as_separate,
    listed.sheet_id,
    listed.not_imported,
    listed.new_result,
    listed.new_owned,
    listed.new_wish,
    listed.unlisted_row,
    listed.equal_row,
    -- What the name, time and date cells of a row that is not imported say, in that order
    CASE WHEN listed.not_imported THEN (
        SELECT jsonb_object_agg(mapped.col ->> 'field', r.cells -> (mapped.col ->> 'column'))
        FROM jsonb_array_elements(CASE WHEN jsonb_typeof(sheet.mapping -> 'columns') = 'array' THEN sheet.mapping -> 'columns' ELSE '[]'::jsonb END) AS mapped(col)
        WHERE mapped.col ->> 'field' IN ('puzzle_name', 'time', 'solved_on')
            AND jsonb_typeof(r.cells) = 'object'
            AND jsonb_exists(r.cells, mapped.col ->> 'column')
    ) END AS file_says,
    COUNT(*) OVER () AS total,
    COUNT(*) FILTER (WHERE listed.not_imported = FALSE) OVER () AS taken
FROM listed
INNER JOIN data_import_row r ON r.id = listed.id
INNER JOIN data_import_sheet sheet ON sheet.id = listed.sheet_id
LEFT JOIN data_import_puzzle import_puzzle ON import_puzzle.id = r.import_puzzle_id AND listed.not_imported = FALSE
LEFT JOIN puzzle ON puzzle.id = import_puzzle.puzzle_id AND import_puzzle.choice = 'catalogue'
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
{$where}
ORDER BY listed.position, listed.row_number, listed.id
LIMIT :rowLimit OFFSET :rowOffset
SQL, [...$parameters, 'selectsMakes' => $makesColumn === null ? 0 : 1])->fetchAllAssociative();

        if ($rows === []) {
            return $none;
        }

        return [
            'rows' => array_map(static function (array $row): ImportAddListRow {
                $fileSays = [];
                $cells = self::decoded($row['file_says']);

                foreach (['puzzle_name', 'time', 'solved_on'] as $field) {
                    $text = is_array($cells[$field] ?? null) ? trim(mb_substr(StoredCells::shown($cells[$field]), 0, 120)) : '';

                    if ($text !== '') {
                        $fileSays[] = $text;
                    }
                }

                return new ImportAddListRow(
                    row: self::addRow($row),
                    sheetId: $row['sheet_id'],
                    sheetTitle: $row['title'],
                    rowNumber: $row['row_number'],
                    notImported: $row['not_imported'],
                    newResult: $row['new_result'],
                    newOwned: $row['new_owned'],
                    newWish: $row['new_wish'],
                    unlisted: $row['unlisted_row'],
                    repeats: $row['equal_row'],
                    fileSays: $fileSays,
                );
            }, $rows),
            'total' => $rows[0]['total'],
            'taken' => $rows[0]['taken'],
        ];
    }

    /**
     * The rows of one distinct puzzle of the import that an "Add" would take now - what "Don't import any of this
     * puzzle" is said about. The same `judged.taken` as the list. One statement.
     *
     * @return list<string>
     */
    public function readyRowIdsOfPuzzle(string $importId, string $playerId, string $importPuzzleId, int $limit): array
    {
        if (self::areIds($importId, $playerId, $importPuzzleId) === false) {
            return [];
        }

        $judged = self::sqlJudged();

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(<<<SQL
WITH {$judged}
SELECT judged.id
FROM judged
CROSS JOIN import
INNER JOIN data_import_row r ON r.id = judged.id
WHERE judged.taken AND r.import_puzzle_id = :importPuzzleId
ORDER BY judged.position, judged.row_number, judged.id
LIMIT :rowLimit
SQL, [...$this->judgedParameters($importId, $playerId), 'importPuzzleId' => strtolower($importPuzzleId), 'rowLimit' => $limit])->fetchFirstColumn();

        return $ids;
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
     * A page of the rows the import added and that an Undo can take back - of one "Add", or of all of them - the
     * newest first. **"What this import added"** (docs/features/data-import/guided-flow.md §7) narrows it: by what a
     * row made, by its sheet, by a part of the puzzle's name. Paged in SQL; one statement.
     *
     * @param null|string $makes `results`, `collection`, `wish` - only the rows that made one
     * @param string $search the player's own text: a part of the puzzle's name - the catalogue's or the file's
     *
     * @return list<ImportAddRow>
     */
    public function added(string $importId, string $playerId, null|string $batchId, int $limit, int $offset, null|string $makes = null, null|string $sheetId = null, string $search = ''): array
    {
        if ($sheetId !== null && Uuid::isValid($sheetId) === false) {
            return [];
        }

        [$condition, $parameters] = $this->addedFilter($makes, $sheetId, $search);

        return $this->listed("r.state = 'added'" . $condition, $importId, $playerId, $batchId, 'r.added_at DESC, sheet.position, r.row_number, r.id', $limit, $offset, $parameters);
    }

    /**
     * What the filters of "What this import added" offer, for the rows the sheet and the search leave: how many
     * rows there are of each kind (the tabs) and - whatever sheet is chosen - how many each sheet has. One
     * statement, whatever the number of rows.
     *
     * @return array{tabs: array{all: int, results: int, collection: int, wish: int}, sheets: list<array{id: string, title: string, rows: int}>}
     */
    public function addedOverview(string $importId, string $playerId, null|string $batchId, null|string $sheetId, string $search): array
    {
        $none = ['tabs' => ['all' => 0, 'results' => 0, 'collection' => 0, 'wish' => 0], 'sheets' => []];

        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false) || ($sheetId !== null && Uuid::isValid($sheetId) === false)) {
            return $none;
        }

        [$searched, $parameters] = $this->addedFilter(null, null, $search);

        /** @var false|array{everything: int, results: int, collection: int, wish: int, sheets: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
WITH found AS (
    SELECT r.sheet_id, r.result_made, r.collection_item_id IS NOT NULL AS owned, r.wish_list_item_id IS NOT NULL AS wished
    FROM data_import_row r
    INNER JOIN data_import ON data_import.id = r.import_id
    LEFT JOIN data_import_puzzle import_puzzle ON import_puzzle.id = r.import_puzzle_id
    LEFT JOIN puzzle ON puzzle.id = import_puzzle.puzzle_id AND import_puzzle.choice = 'catalogue'
    WHERE r.import_id = :importId
        AND data_import.player_id = :playerId
        AND data_import.status <> 'discarded'
        AND r.state = 'added'
        AND (CAST(:batchId AS UUID) IS NULL OR r.batch_id = CAST(:batchId AS UUID))
        {$searched}
)
SELECT
    COUNT(*) FILTER (WHERE CAST(:chosenSheet AS UUID) IS NULL OR found.sheet_id = CAST(:chosenSheet AS UUID)) AS everything,
    COUNT(*) FILTER (WHERE found.result_made AND (CAST(:chosenSheet AS UUID) IS NULL OR found.sheet_id = CAST(:chosenSheet AS UUID))) AS results,
    COUNT(*) FILTER (WHERE found.owned AND (CAST(:chosenSheet AS UUID) IS NULL OR found.sheet_id = CAST(:chosenSheet AS UUID))) AS collection,
    COUNT(*) FILTER (WHERE found.wished AND (CAST(:chosenSheet AS UUID) IS NULL OR found.sheet_id = CAST(:chosenSheet AS UUID))) AS wish,
    (
        SELECT jsonb_agg(jsonb_build_object('id', sheet.id, 'title', sheet.title, 'rows', per_sheet.rows) ORDER BY sheet.position)
        FROM (SELECT found.sheet_id, COUNT(*) AS rows FROM found GROUP BY found.sheet_id) per_sheet
        INNER JOIN data_import_sheet sheet ON sheet.id = per_sheet.sheet_id
    ) AS sheets
FROM found
SQL, [...$parameters, 'importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId, 'chosenSheet' => $sheetId])->fetchAssociative();

        if ($row === false) {
            return $none;
        }

        $sheets = [];

        foreach (self::decoded($row['sheets']) as $sheet) {
            if (is_array($sheet) && is_string($sheet['id'] ?? null) && is_string($sheet['title'] ?? null) && is_int($sheet['rows'] ?? null)) {
                $sheets[] = ['id' => $sheet['id'], 'title' => $sheet['title'], 'rows' => $sheet['rows']];
            }
        }

        return [
            'tabs' => ['all' => $row['everything'], 'results' => $row['results'], 'collection' => $row['collection'], 'wish' => $row['wish']],
            'sheets' => $sheets,
        ];
    }

    /**
     * The filters of "What this import added" as a condition on the row `r` (and the catalogue `puzzle` it was
     * matched with) and what it names.
     *
     * @return array{string, array<string, mixed>}
     */
    private function addedFilter(null|string $makes, null|string $sheetId, string $search): array
    {
        $condition = match ($makes) {
            'results' => ' AND r.result_made',
            'collection' => ' AND r.collection_item_id IS NOT NULL',
            'wish' => ' AND r.wish_list_item_id IS NOT NULL',
            default => '',
        };
        $parameters = [];

        if ($sheetId !== null) {
            $condition .= ' AND r.sheet_id = :sheetId';
            $parameters['sheetId'] = strtolower($sheetId);
        }

        $search = trim(mb_substr($search, 0, 100));

        if ($search !== '') {
            // The file's own name by the text as typed; the catalogue's names through the one puzzle text search
            $textSearch = PuzzleTextSearch::fromUserInput($search);
            $catalogue = $textSearch->isEmpty() ? 'FALSE' : '(puzzle.id IS NOT NULL AND ' . $textSearch->condition('puzzle') . ')';
            $condition .= " AND (LOWER(r.parsed ->> 'name') LIKE :needle OR {$catalogue})";
            $parameters = [...$parameters, ...$textSearch->parameters($this->clock->now()), 'needle' => '%' . addcslashes(mb_strtolower($search), '%_\\') . '%'];
        }

        return [$condition, $parameters];
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
     * @param null|list<string> $rowIds only these rows of the import ("Undo the ticked")
     *
     * `keptJustForYou`: of the added rows, those kept just for the player (their puzzle is not in the database) - an
     * Undo removes what they made there, unless it was matched with a puzzle of the database since.
     *
     * @return array{added: int, results: int, collectionItems: int, wishListItems: int, wishListItemsToRestore: int, notAdded: int, keptJustForYou: int, keptJustForYouRowIds: list<string>}
     */
    public function counts(string $importId, string $playerId, null|string $batchId = null, null|string $rowId = null, null|array $rowIds = null): array
    {
        $none = ['added' => 0, 'results' => 0, 'collectionItems' => 0, 'wishListItems' => 0, 'wishListItemsToRestore' => 0, 'notAdded' => 0, 'keptJustForYou' => 0, 'keptJustForYouRowIds' => []];

        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false) || ($rowId !== null && Uuid::isValid($rowId) === false)) {
            return $none;
        }

        // Several rows ("Undo the ticked"): the ids that are ids - with none of them, nothing is counted
        $several = '';
        $parameters = [];
        $types = [];

        if ($rowIds !== null) {
            $rowIds = array_values(array_unique(array_map(strtolower(...), array_filter($rowIds, Uuid::isValid(...)))));

            if ($rowIds === []) {
                return $none;
            }

            $several = 'AND r.id IN (:rowIds)';
            $parameters = ['rowIds' => $rowIds];
            $types = ['rowIds' => ArrayParameterType::STRING];
        }

        $notAdded = self::NOT_ADDED;

        /** @var false|array{added: int, results: int, collection_items: int, wish_list_items: int, wish_list_items_to_restore: int|string, not_added: int, kept_just_for_you: int, kept_just_for_you_row_ids: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL) AS added,
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.result_made) AS results,
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.collection_item_id IS NOT NULL) AS collection_items,
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.wish_list_item_id IS NOT NULL) AS wish_list_items,
    COALESCE(SUM(jsonb_array_length(COALESCE(r.made -> 'removedWishListItems', '[]'::jsonb))) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL), 0) AS wish_list_items_to_restore,
    COUNT(*) FILTER (WHERE {$notAdded}) AS not_added,
    COUNT(*) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.made -> 'unlisted' IS NOT NULL) AS kept_just_for_you,
    json_agg(r.id) FILTER (WHERE r.state = 'added' AND r.undo_id IS NULL AND r.made -> 'unlisted' IS NOT NULL) AS kept_just_for_you_row_ids
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
    AND (CAST(:batchId AS UUID) IS NULL OR r.batch_id = CAST(:batchId AS UUID))
    AND (CAST(:rowId AS UUID) IS NULL OR r.id = CAST(:rowId AS UUID))
    {$several}
SQL, [...$parameters, 'importId' => $importId, 'playerId' => $playerId, 'batchId' => $batchId, 'rowId' => $rowId], $types)->fetchAssociative();

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
            'keptJustForYou' => $row['kept_just_for_you'],
            'keptJustForYouRowIds' => array_values(array_filter((array) json_decode($row['kept_just_for_you_row_ids'] ?? '[]', true), is_string(...))),
        ];
    }

    /**
     * Of these rows of the player's import, the ones kept just for the player: the id of the puzzle "kept just for
     * you" each belongs to, as the row's own record says it (`made`) - where an Undo that left such a row alone
     * sends the player. One statement.
     *
     * @param list<string> $rowIds
     *
     * @return array<string, string> row id => the id on the player's own page of puzzles kept just for them
     */
    public function keptJustForYouOfRows(string $importId, string $playerId, array $rowIds): array
    {
        $rowIds = array_values(array_unique(array_map(strtolower(...), array_filter($rowIds, Uuid::isValid(...)))));

        if (self::areIds($importId, $playerId) === false || $rowIds === []) {
            return [];
        }

        /** @var array<string, string> $ids */
        $ids = $this->database->executeQuery(<<<SQL
SELECT r.id, r.made -> 'unlisted' ->> 'puzzleId'
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND r.id IN (:rowIds)
    AND r.made -> 'unlisted' ->> 'puzzleId' IS NOT NULL
SQL, ['importId' => $importId, 'playerId' => $playerId, 'rowIds' => $rowIds], ['rowIds' => ArrayParameterType::STRING])->fetchAllKeyValue();

        return array_filter($ids, Uuid::isValid(...));
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
     * @return array{id: string, code: string, action: string, beforeAdd: bool, ofBatchId: null|string, running: bool, stale: bool, total: int, done: int, answered: int, stillWaiting: int, batchId: null|string, addStatus: null|string}
     *
     * @throws DataImportNotFound
     */
    public function groupAnswer(string $importId, string $playerId, string $answerId): array
    {
        self::assertIds($importId, $playerId, $answerId);

        /** @var false|array{id: string, code: string, action: string, before_add: bool, of_batch_id: null|string, state: string, last_activity_at: string, rows_total: int, rows_done: int, rows_answered: int, rows_still_waiting: int, batch_id: null|string, add_status: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT answer.id, answer.code, answer.action, answer.before_add, answer.of_batch_id, answer.state, answer.last_activity_at,
    answer.rows_total, answer.rows_done, answer.rows_answered, answer.rows_still_waiting, answer.batch_id, answer.add_status
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
            // Rows that took the answer and wait with the other question an "Add" found on them
            'stillWaiting' => $row['rows_still_waiting'],
            'batchId' => $row['batch_id'],
            'addStatus' => $row['add_status'],
        ];
    }

    /**
     * How many rows of the import (of one "Add", or of all) wait with each reason - what the group cards say - and
     * how many results with a time the import has at all (what "most of the times in this file" is measured by),
     * and `also`: of the rows of a reason, how many wait with another question too (by that question's code -
     * `open_questions`; today only "the file says it is on MySpeedPuzzling" beside an unusual time), so a card can
     * ask both on one screen. One statement.
     *
     * @return array{reasons: array<string, int>, timedResults: int, also: array<string, array<string, int>>}
     */
    public function notAddedByReason(string $importId, string $playerId, null|string $batchId): array
    {
        $none = ['reasons' => [], 'timedResults' => 0, 'also' => []];

        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false)) {
            return $none;
        }

        /** @var list<array{code: null|string, also: null|string, rows: int}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT r.failure AS code, NULL AS also, COUNT(*) AS rows
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

-- The rows that wait with a second question beside their reason (DataImportRow::openQuestions())
SELECT r.failure, other.code, COUNT(*)
FROM data_import_row r
INNER JOIN data_import ON data_import.id = r.import_id
CROSS JOIN LATERAL jsonb_array_elements_text(r.open_questions) AS other(code)
WHERE r.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
    AND r.state = 'waiting'
    AND r.failure IS NOT NULL
    AND jsonb_typeof(r.open_questions) = 'array'
    AND other.code <> r.failure
    AND (CAST(:batchId AS UUID) IS NULL OR r.batch_id = CAST(:batchId AS UUID))
GROUP BY r.failure, other.code

UNION ALL

SELECT NULL, NULL, COUNT(*)
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
            } elseif ($row['also'] !== null) {
                $none['also'][$row['code']][$row['also']] = $row['rows'];
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
     * @return array{id: string, scope: string, batchId: null|string, running: bool, byAdmin: bool, startedAt: DateTimeImmutable, finishedAt: null|DateTimeImmutable, rows: int, left: int, done: int, rowsUndone: int, resultsDeleted: int, keptRowsRemoved: int, resultsKeptEdited: int, collectionItemsRemoved: int, collectionItemsKept: int, wishListItemsRemoved: int, wishListItemsRestored: int, rowsFailed: int}
     *
     * @throws DataImportNotFound
     */
    public function undo(string $importId, string $playerId, string $undoId): array
    {
        self::assertIds($importId, $playerId, $undoId);

        /** @var false|array{id: string, scope: string, batch_id: null|string, state: string, by_admin: bool, started_at: string, finished_at: null|string, rows_requested: int, rows_left: int, rows_undone: int, results_deleted: int, kept_rows_removed: int, results_kept_edited: int, collection_items_removed: int, collection_items_kept: int, wish_list_items_removed: int, wish_list_items_restored: int, rows_failed: int} $row */
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
    undo.kept_rows_removed,
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
            'keptRowsRemoved' => $row['kept_rows_removed'],
            'resultsKeptEdited' => $row['results_kept_edited'],
            'collectionItemsRemoved' => $row['collection_items_removed'],
            'collectionItemsKept' => $row['collection_items_kept'],
            'wishListItemsRemoved' => $row['wish_list_items_removed'],
            'wishListItemsRestored' => $row['wish_list_items_restored'],
            'rowsFailed' => $row['rows_failed'],
        ];
    }

    /**
     * Where each of the player's imports stands - for Imports home (docs/features/data-import/guided-flow.md §5.1):
     * what it added, whether it is adding right now, and what is left - rows ready to add (the engine's own
     * definition, DataImportRowRepository::sqlReadyRows(), over the imports that can still add), rows that wait for
     * an answer after an "Add", questions of its sheets, rows we could not read, puzzles still to match. **One
     * statement for all of them**, whatever their number and size. Numbers only; nobody's identity is selected.
     *
     * @return array<string, array{results: int, collectionItems: int, wishListItems: int, keptJustForYou: int, lastAddedAt: null|DateTimeImmutable, adding: bool, runningBatchId: null|string, resultsToday: int, ready: int, waiting: int, questions: int, look: int, toMatch: int, working: bool, tookRows: bool}> by import id
     */
    public function addedByImports(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        $ready = DataImportRowRepository::sqlReadyRows(imports: <<<SQL
r.import_id IN (
    SELECT open_import.id
    FROM data_import open_import
    WHERE open_import.player_id = :playerId
        AND open_import.status IN ('setting_up', 'reviewing')
        AND open_import.cells_wiped_at IS NULL
)
SQL);
        $notAdded = self::NOT_ADDED;

        /** @var list<array{import_id: string, results: int, collection_items: int, wish_list_items: int, kept_just_for_you: int, last_added_at: null|string, running_batch_id: null|string, results_today: int, ready: int, waiting: int, took_rows: bool, questions: int|string, look: int|string, to_match: int, working: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
WITH {$ready},
ready_by AS (
    SELECT counted.import_id, COUNT(*) AS rows
    FROM ready
    INNER JOIN data_import_row counted ON counted.id = ready.id
    GROUP BY counted.import_id
),
taken_by AS (
    SELECT
        r.import_id,
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.result_made) AS results,
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.collection_item_id IS NOT NULL) AS collection_items,
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.wish_list_item_id IS NOT NULL) AS wish_list_items,
        -- Rows kept just for the player: nothing of them is on the profile, but they are the player's data all the same
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.made -> 'unlisted' IS NOT NULL AND r.result_made = FALSE AND r.collection_item_id IS NULL AND r.wish_list_item_id IS NULL) AS kept_just_for_you,
        MAX(r.added_at) FILTER (WHERE r.state = 'added') AS last_added_at,
        COUNT(*) FILTER (WHERE r.state = 'added' AND r.result_made AND r.added_at >= :startOfDay) AS results_today,
        COUNT(*) FILTER (WHERE {$notAdded}) AS waiting
    FROM data_import_row r
    INNER JOIN data_import ON data_import.id = r.import_id
    WHERE data_import.player_id = :playerId
        AND data_import.status <> 'discarded'
        AND r.batch_id IS NOT NULL
    GROUP BY r.import_id
)
SELECT
    data_import.id AS import_id,
    COALESCE(taken_by.results, 0) AS results,
    COALESCE(taken_by.collection_items, 0) AS collection_items,
    COALESCE(taken_by.wish_list_items, 0) AS wish_list_items,
    COALESCE(taken_by.kept_just_for_you, 0) AS kept_just_for_you,
    taken_by.last_added_at,
    COALESCE(taken_by.results_today, 0) AS results_today,
    COALESCE(taken_by.waiting, 0) AS waiting,
    taken_by.import_id IS NOT NULL AS took_rows,
    (SELECT batch.id FROM data_import_batch batch WHERE batch.import_id = data_import.id AND batch.state = 'running' ORDER BY batch.id DESC LIMIT 1) AS running_batch_id,
    COALESCE(ready_by.rows, 0) AS ready,
    -- The questions of its sheets and the rows we could not read, as "Where you are" counts them (ImportFlow)
    (
        SELECT COALESCE(SUM(jsonb_array_length(COALESCE(sheet.mapping -> 'questions', '[]'::jsonb))), 0)
        FROM data_import_sheet sheet
        WHERE sheet.import_id = data_import.id
            AND sheet.mapping_version > 0
            AND sheet.set_up_failure IS NULL
            AND COALESCE(sheet.kind, '') NOT IN ('not_puzzles', 'somebody_else')
    ) AS questions,
    (
        SELECT COALESCE(SUM(sheet.rows_need_look), 0)
        FROM data_import_sheet sheet
        WHERE sheet.import_id = data_import.id
            AND sheet.mapping_version > 0
            AND sheet.parsed_version = sheet.mapping_version
            AND sheet.set_up_failure IS NULL
            AND COALESCE(sheet.kind, '') NOT IN ('not_puzzles', 'somebody_else')
    ) AS look,
    (SELECT COUNT(*) FROM data_import_puzzle waiting_puzzle WHERE waiting_puzzle.import_id = data_import.id AND waiting_puzzle.choice = 'undecided') AS to_match,
    (
        EXISTS (
            SELECT 1 FROM data_import_sheet sheet
            WHERE sheet.import_id = data_import.id
                AND sheet.set_up_failure IS NULL
                AND (sheet.mapping_version = 0 OR sheet.parsed_version IS DISTINCT FROM sheet.mapping_version)
        )
        OR (
            data_import.paired_at IS NULL
            AND data_import.pairing_requeues < :maxRequeues
            AND EXISTS (SELECT 1 FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.parsed_version IS NOT NULL)
        )
    ) AS working
FROM data_import
LEFT JOIN taken_by ON taken_by.import_id = data_import.id
LEFT JOIN ready_by ON ready_by.import_id = data_import.id
WHERE data_import.player_id = :playerId
    AND data_import.status <> 'discarded'
SQL, [
            'playerId' => $playerId,
            'explicit' => 0,
            'maxRequeues' => DataImportLimits::MAX_SET_UP_ATTEMPTS,
            'startOfDay' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $byImport = [];

        foreach ($rows as $row) {
            $byImport[$row['import_id']] = [
                'results' => $row['results'],
                'collectionItems' => $row['collection_items'],
                'wishListItems' => $row['wish_list_items'],
                'keptJustForYou' => $row['kept_just_for_you'],
                'lastAddedAt' => $row['last_added_at'] === null ? null : new DateTimeImmutable($row['last_added_at']),
                'adding' => $row['running_batch_id'] !== null,
                'runningBatchId' => $row['running_batch_id'],
                'resultsToday' => $row['results_today'],
                'ready' => $row['ready'],
                'waiting' => $row['waiting'],
                'questions' => (int) $row['questions'],
                'look' => (int) $row['look'],
                'toMatch' => $row['to_match'],
                'working' => $row['working'],
                'tookRows' => $row['took_rows'],
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
     * @param array<string, mixed> $more what $condition names besides the import, the player and the batch
     *
     * @return list<ImportAddRow>
     */
    private function listed(string $condition, string $importId, string $playerId, null|string $batchId, string $order, int $limit, int $offset, array $more = []): array
    {
        if (self::areIds($importId, $playerId) === false || ($batchId !== null && Uuid::isValid($batchId) === false)) {
            return [];
        }

        $parameters = [...$more, 'importId' => $importId, 'playerId' => $playerId];

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

        /** @var list<array{id: string, row_number: int, kind: null|string, parsed: null|string, title: string, state: string, failure: null|string, import_puzzle_id: null|string, puzzle_id: null|string, puzzle_name: null|string, brand_name: null|string, pieces_count: null|int, image: null|string, image_ratio: null|float|string, solving_time_id: null|string, made_collection_item: bool, made_wish_list_item: bool, added_at: null|string, kept_by_undo: bool, being_taken_back: bool, batch_id: null|string, equal_rows: int, add_as_separate: bool, open_questions: null|string}> $rows */
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
    CASE WHEN r.state = 'waiting' AND r.failure IS NOT NULL THEN r.open_questions END AS open_questions,
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

        return array_map(self::addRow(...), $rows);
    }

    /**
     * @param array{id: string, row_number: int, kind: null|string, parsed: null|string, title: string, state: string, failure: null|string, import_puzzle_id: null|string, puzzle_id: null|string, puzzle_name: null|string, brand_name: null|string, pieces_count: null|int, image: null|string, image_ratio: null|float|string, solving_time_id: null|string, made_collection_item: bool, made_wish_list_item: bool, added_at: null|string, kept_by_undo: bool, being_taken_back: bool, batch_id: null|string, equal_rows: int, add_as_separate: bool, ...} $row
     */
    private static function addRow(array $row): ImportAddRow
    {
        return new ImportAddRow(
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
            alsoWaitsWith: self::alsoWaitsWith($row['open_questions'] ?? null, $row['failure']),
        );
    }

    /**
     * The other questions a row waits with beside its reason (`data_import_row.open_questions`).
     *
     * @return list<string>
     */
    private static function alsoWaitsWith(mixed $openQuestions, null|string $failure): array
    {
        $questions = is_string($openQuestions) ? json_decode($openQuestions, true) : null;

        if (is_array($questions) === false || $failure === null) {
            return [];
        }

        return array_values(array_filter($questions, static fn (mixed $code): bool => is_string($code) && $code !== $failure));
    }

    /**
     * What sqlJudged() names.
     *
     * @return array{importId: string, playerId: string, explicit: int, limit: int, startOfDay: string}
     */
    private function judgedParameters(string $importId, string $playerId): array
    {
        return [
            'importId' => $importId,
            'playerId' => $playerId,
            'explicit' => 0,
            'limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY,
            'startOfDay' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->format('Y-m-d H:i:s'),
        ];
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
