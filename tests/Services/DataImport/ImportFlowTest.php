<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Results\DataImportSheetOverview;
use SpeedPuzzling\Web\Results\ImportAddSummary;
use SpeedPuzzling\Web\Results\ImportFlowStep;
use SpeedPuzzling\Web\Results\ImportFlowText;
use SpeedPuzzling\Web\Results\ImportPairingSummary;
use SpeedPuzzling\Web\Services\DataImport\ImportFlow;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * The import's guided flow as a table (docs/features/data-import/guided-flow.md §2 "When a step is passed by
 * itself", §5.3): what the import's page has loaded → the five step states, the status sentence of each, the ONE
 * primary action and the "add what is ready now" line. Nothing here touches a database or a clock.
 */
final class ImportFlowTest extends TestCase
{
    private const string IMPORT = '019a0000-0000-7000-8000-000000000001';
    private const string BATCH = '019a0000-0000-7000-8000-0000000000b1';
    private const string SHEET = '019a0000-0000-7000-8000-0000000000c1';

    /**
     * @param array{status?: DataImportStatus, sheets?: list<DataImportSheetOverview>, pairing?: ImportPairingSummary, add?: array<string, mixed>, people?: null|array{people: int, players: int, unanswered: int}} $given
     * @param array<int, string> $states by step number
     * @param array<int, list<string>> $lines the message keys of each step's status, by step number
     */
    #[DataProvider('provideFlows')]
    public function testTheFlowOfAnImport(array $given, string $stage, array $states, array $lines, null|string $primary, null|string $primaryRoute, null|int $current, null|int $readyNow): void
    {
        $flow = self::flow($given);

        self::assertSame($stage, $flow->stage);
        self::assertSame($states, array_map(static fn (ImportFlowStep $step): string => $step->state, $flow->steps));

        foreach ($lines as $number => $keys) {
            self::assertSame($keys, array_map(static fn (ImportFlowText $line): string => $line->key, $flow->steps[$number]->lines), 'the status of step ' . $number);
        }

        self::assertSame($primary, $flow->primary?->label->key);
        self::assertSame($primaryRoute, $flow->primary?->route);
        self::assertSame($current, $flow->current());
        self::assertSame($readyNow, $flow->secondary?->label->count, 'the "add what is ready now" line');

        // The numbers of the steps never change
        self::assertSame([1, 2, 3, 4, 5], array_keys($flow->steps));
        self::assertSame(['file', 'check', 'people', 'puzzles', 'add'], array_values(array_map(static fn (ImportFlowStep $step): string => $step->key, $flow->steps)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, array<int, string>, array<int, list<string>>, null|string, null|string, null|int, null|int}>
     */
    public static function provideFlows(): iterable
    {
        $done = ImportFlowStep::DONE;
        $open = ImportFlowStep::OPEN;
        $working = ImportFlowStep::WORKING;
        $ahead = ImportFlowStep::AHEAD;

        yield 'the upload did not finish: only step 1 says something, the page has the button' => [
            ['status' => DataImportStatus::Uploading],
            ImportFlow::STAGE_FILE,
            [1 => $open, 2 => $ahead, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [1 => ['data_import.flow.file.upload_not_finished'], 2 => [], 5 => []],
            null, null, 1, null,
        ];

        yield 'reading in progress: nothing to press' => [
            ['status' => DataImportStatus::Reading],
            ImportFlow::STAGE_FILE,
            [1 => $working, 2 => $ahead, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [1 => ['data_import.flow.file.being_read']],
            null, null, 1, null,
        ];

        yield 'a Google sheet being fetched reads like a file being read' => [
            ['status' => DataImportStatus::Fetching],
            ImportFlow::STAGE_FILE,
            [1 => $working, 2 => $ahead, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [1 => ['data_import.flow.file.being_read']],
            null, null, 1, null,
        ];

        yield 'the file could not be read' => [
            ['status' => DataImportStatus::ReadFailed],
            ImportFlow::STAGE_FILE,
            [1 => $open, 2 => $ahead, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [1 => ['data_import.flow.file.not_read']],
            null, null, 1, null,
        ];

        yield 'the sheets are still worked out: step 2 works by itself' => [
            ['sheets' => [self::sheet(mapped: false)], 'pairing' => new ImportPairingSummary(0, 0, 0, 0, 0, 0, 0, false)],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $working, 3 => $ahead, 4 => $working, 5 => $ahead],
            // Step 2 works by itself: step 5 does not send the player there
            [2 => ['data_import.flow.check.working'], 4 => ['data_import.flow.puzzles.working'], 5 => ['data_import.flow.add.nothing_reading']],
            null, null, 2, null,
        ];

        yield 'the clean export: steps 2-4 passed by themselves, straight to "Check and add"' => [
            ['add' => ['rowsReady' => 471, 'rowsTaken' => 471, 'results' => 212, 'owned' => 255, 'wishList' => 4]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $open],
            [
                1 => ['data_import.flow.file.sheets_rows'],
                2 => ['data_import.flow.check.done'],
                3 => ['data_import.flow.people.default'],
                4 => ['data_import.flow.puzzles.all_matched'],
                5 => ['data_import.flow.add.ready'],
            ],
            'data_import.flow.primary.check_and_add', 'data_import_add', 5, null,
        ];

        yield 'questions about the file come first, and what is ready can be added now' => [
            [
                'sheets' => [self::sheet(questions: 2, rowsWaiting: 212, rowsUnderstood: 100), self::sheet(rowsNeedLook: 45)],
                'add' => ['rowsReady' => 300, 'rowsTaken' => 300, 'results' => 300, 'needLook' => 45],
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $open],
            [2 => ['data_import.flow.check.questions', 'data_import.flow.check.rows']],
            'data_import.flow.primary.questions', 'data_import_columns', 2, 300,
        ];

        // ---- A sheet whose reading is a guess is checked first (ImportCheckFirst) ----
        yield 'a sheet without a header row: "check how we read your file" before rows, people and puzzles' => [
            [
                'sheets' => [self::sheet(rowsUnderstood: 0, rowsWaiting: 72, columnsToCheck: 5, checkFirst: 'no_header', sheetId: self::SHEET)],
                'pairing' => new ImportPairingSummary(0, 0, 0, 0, 0, 0, 0, true),
                'people' => ['people' => 3, 'players' => 0, 'unanswered' => 3],
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $open, 4 => $ahead, 5 => $ahead],
            [
                2 => ['data_import.flow.check.check_first.no_header'],
                // Said, instead of a spinner: nothing is matched from it until the player has looked
                4 => ['data_import.flow.puzzles.held'],
                5 => ['data_import.flow.add.nothing_step'],
            ],
            'data_import.flow.primary.check_first', 'data_import_sheet', 2, null,
        ];

        yield 'a sheet with headers whose deciding column was guessed from its values (or whose kind is unknown): the same button, another sentence' => [
            ['sheets' => [self::sheet(rowsUnderstood: 0, rowsWaiting: 40, kindSure: false, checkFirst: 'unsure', sheetId: self::SHEET)], 'pairing' => new ImportPairingSummary(0, 0, 0, 0, 0, 0, 0, true)],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [2 => ['data_import.flow.check.check_first.unsure'], 4 => ['data_import.flow.puzzles.held']],
            'data_import.flow.primary.check_first', 'data_import_sheet', 2, null,
        ];

        yield 'a guessed sheet beside a sure one: the sure one is matched and can be added now, the check comes before its rows and puzzles' => [
            [
                'sheets' => [self::sheet(rowsNeedLook: 45), self::sheet(rowsUnderstood: 0, rowsWaiting: 72, checkFirst: 'no_header', sheetId: self::SHEET)],
                'pairing' => new ImportPairingSummary(200, 180, 0, 0, 20, 0, 0, true),
                'add' => ['rowsReady' => 180, 'rowsTaken' => 180, 'results' => 180, 'needLook' => 45, 'undecided' => 20],
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $open, 5 => $open],
            [
                2 => ['data_import.flow.check.check_first.no_header_sheet', 'data_import.flow.check.rows'],
                4 => ['data_import.flow.puzzles.matched', 'data_import.flow.puzzles.rows_wait', 'data_import.flow.puzzles.held'],
            ],
            'data_import.flow.primary.check_first', 'data_import_sheet', 2, 180,
        ];

        yield 'questions of a sheet we are sure about still come before the check' => [
            ['sheets' => [self::sheet(questions: 1, rowsWaiting: 10), self::sheet(rowsUnderstood: 0, rowsWaiting: 72, checkFirst: 'no_header', sheetId: self::SHEET)]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [2 => ['data_import.flow.check.questions', 'data_import.flow.check.check_first.no_header_sheet']],
            'data_import.flow.primary.questions', 'data_import_columns', 2, null,
        ];

        yield 'a found header row with probable columns or a probable kind is not held: the old line, the puzzles at once' => [
            ['sheets' => [self::sheet(columnsToCheck: 2, kindSure: false)], 'add' => ['undecided' => 12], 'pairing' => new ImportPairingSummary(60, 48, 0, 12, 0, 0, 0, true)],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $open, 5 => $ahead],
            [2 => ['data_import.flow.check.unsure'], 4 => ['data_import.flow.puzzles.matched', 'data_import.flow.puzzles.rows_wait']],
            'data_import.flow.primary.puzzles', 'data_import_puzzles', 4, null,
        ];

        yield 'rows are read again after a save: step 2 says so, step 4 waits for them - no tick before the matching ran' => [
            ['sheets' => [new DataImportSheetOverview(position: 0, title: 'Log', hidden: false, rows: 72, columns: 8, kind: DataImportSheetKind::Results, mapped: true, parsed: false)], 'pairing' => new ImportPairingSummary(0, 0, 0, 0, 0, 0, 0, true)],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $working, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [2 => ['data_import.flow.check.reading'], 4 => ['data_import.flow.puzzles.waiting_rows']],
            null, null, 2, null,
        ];

        yield 'every puzzle waits for the check and the matching has not come to rest: the step waits for the player - no spinner' => [
            [
                'sheets' => [self::sheet(rowsUnderstood: 0, rowsWaiting: 72, checkFirst: 'no_header', sheetId: self::SHEET), self::sheet(rowsUnderstood: 0, rowsWaiting: 20, checkFirst: 'no_header', sheetId: self::SHEET)],
                'pairing' => new ImportPairingSummary(0, 0, 0, 0, 0, 0, 0, false),
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [4 => ['data_import.flow.puzzles.held'], 5 => ['data_import.flow.add.nothing_step']],
            'data_import.flow.primary.check_first', 'data_import_sheet', 2, null,
        ];

        yield 'several sheets, one without a header row: "one sheet of your file"' => [
            ['sheets' => [self::sheet(), self::sheet(rowsUnderstood: 0, rowsWaiting: 72, checkFirst: 'no_header', sheetId: self::SHEET)]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $ahead, 5 => $ahead],
            [2 => ['data_import.flow.check.check_first.no_header_sheet']],
            'data_import.flow.primary.check_first', 'data_import_sheet', 2, null,
        ];

        yield 'a sheet whose kind is not sure: "not sure about 1 sheet"' => [
            ['sheets' => [self::sheet(kindSure: false, columnsToCheck: 1)]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $ahead],
            [2 => ['data_import.flow.check.unsure']],
            null, null, 2, null,
        ];

        yield 'the player confirmed the columns: the sheet is like any other - its rows, then its puzzles' => [
            ['sheets' => [self::sheet(columnsToCheck: 5)], 'add' => ['undecided' => 12], 'pairing' => new ImportPairingSummary(60, 48, 0, 12, 0, 0, 0, true)],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $open, 5 => $ahead],
            [2 => ['data_import.flow.check.unsure_columns'], 4 => ['data_import.flow.puzzles.matched', 'data_import.flow.puzzles.rows_wait']],
            'data_import.flow.primary.puzzles', 'data_import_puzzles', 4, null,
        ];

        yield 'a guessed sheet that is not imported or failed asks for no check' => [
            [
                'sheets' => [
                    self::sheet(kind: DataImportSheetKind::NotPuzzles, checkFirst: 'no_header', sheetId: self::SHEET),
                    self::sheet(failed: true, checkFirst: 'no_header', sheetId: self::SHEET),
                ],
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $ahead],
            [2 => ['data_import.flow.check.failed']],
            'data_import.flow.primary.how_read', 'data_import_columns', 2, null,
        ];

        yield 'everything of the file is on the profile already: said as the status - nothing to press, nothing "first"' => [
            ['add' => ['rowsReady' => 20, 'rowsTaken' => 20, 'alreadyYours' => 20, 'onProfile' => 20]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $done],
            [5 => ['data_import.flow.add.already_yours']],
            null, null, null, null,
        ];

        yield 'some rows are on the profile already, others are new: both said, and the Add is for the new ones' => [
            ['add' => ['rowsReady' => 25, 'rowsTaken' => 25, 'results' => 5, 'alreadyYours' => 20, 'onProfile' => 20]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $open],
            [5 => ['data_import.flow.add.ready', 'data_import.flow.add.already_yours']],
            'data_import.flow.primary.check_and_add', 'data_import_add', 5, null,
        ];

        yield 'rows on the profile already while a step still waits: the status says both, never "nothing is ready"' => [
            ['add' => ['rowsReady' => 20, 'rowsTaken' => 20, 'alreadyYours' => 20, 'onProfile' => 20, 'undecided' => 4], 'pairing' => new ImportPairingSummary(10, 6, 0, 4, 0, 0, 0, true)],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $open, 5 => $ahead],
            [5 => ['data_import.flow.add.already_yours']],
            'data_import.flow.primary.puzzles', 'data_import_puzzles', 4, null,
        ];

        yield 'only rows that could not be read: the primary leads to them' => [
            ['sheets' => [self::sheet(rowsNeedLook: 45)], 'add' => ['needLook' => 45]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $ahead],
            [2 => ['data_import.flow.check.rows'], 5 => ['data_import.flow.add.nothing_step']],
            'data_import.flow.primary.rows', 'data_import_to_fix', 2, null,
        ];

        yield 'a question on a sheet that is not puzzles asks nobody' => [
            ['sheets' => [self::sheet(), self::sheet(kind: DataImportSheetKind::NotPuzzles, questions: 1)]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $ahead],
            [2 => ['data_import.flow.check.done'], 5 => ['data_import.flow.add.nothing']],
            null, null, 5, null,
        ];

        yield 'a sheet that failed on our side keeps step 2 open, calmly' => [
            ['sheets' => [self::sheet(), self::sheet(failed: true)]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $ahead],
            [2 => ['data_import.flow.check.failed']],
            'data_import.flow.primary.how_read', 'data_import_columns', 2, null,
        ];

        yield 'a sheet we are not sure about: step 2 is not passed by itself - but never the one thing asked for' => [
            [
                'sheets' => [self::sheet(), self::sheet(columnsToCheck: 1), self::sheet(kindSure: false), self::sheet(kind: DataImportSheetKind::NotPuzzles, columnsToCheck: 3)],
                'add' => ['rowsReady' => 300, 'rowsTaken' => 300, 'results' => 300],
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $open],
            [2 => ['data_import.flow.check.unsure']],
            'data_import.flow.primary.check_and_add', 'data_import_add', 5, null,
        ];

        yield 'a sheet that asks is counted as a question, not as one we are not sure about' => [
            ['sheets' => [self::sheet(questions: 1, rowsWaiting: 10, columnsToCheck: 2)]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $ahead],
            [2 => ['data_import.flow.check.questions']],
            'data_import.flow.primary.questions', 'data_import_columns', 2, null,
        ];

        yield 'nobody else is named: step 3 passed by itself' => [
            ['people' => ['people' => 0, 'players' => 0, 'unanswered' => 0], 'add' => ['rowsReady' => 5, 'rowsTaken' => 5, 'results' => 5]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $done, 4 => $done, 5 => $open],
            [3 => ['data_import.flow.people.nobody']],
            'data_import.flow.primary.check_and_add', 'data_import_add', 5, null,
        ];

        yield 'names nobody said anything about: "Say who they are"' => [
            ['people' => ['people' => 3, 'players' => 1, 'unanswered' => 2], 'add' => ['rowsReady' => 5, 'rowsTaken' => 5, 'results' => 5]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $open, 4 => $done, 5 => $open],
            [3 => ['data_import.flow.people.tell_us']],
            'data_import.flow.primary.people', 'data_import_people', 3, 5,
        ];

        yield 'every name has its answer' => [
            ['people' => ['people' => 3, 'players' => 1, 'unanswered' => 0]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $done, 4 => $done, 5 => $ahead],
            [3 => ['data_import.flow.people.named']],
            null, null, 5, null,
        ];

        yield 'puzzles wait: "Match your puzzles", with what is ready beside it' => [
            [
                'pairing' => new ImportPairingSummary(786, 510, 80, 196, 0, 0, 0, true),
                'add' => ['rowsReady' => 471, 'rowsTaken' => 471, 'results' => 471, 'undecided' => 395],
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $open, 5 => $open],
            [4 => ['data_import.flow.puzzles.matched', 'data_import.flow.puzzles.rows_wait'], 5 => ['data_import.flow.add.ready']],
            'data_import.flow.primary.puzzles', 'data_import_puzzles', 4, 471,
        ];

        yield 'the matching still runs and nothing waits yet: no button' => [
            ['pairing' => new ImportPairingSummary(786, 300, 0, 0, 0, 0, 486, false)],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $working, 5 => $ahead],
            [4 => ['data_import.flow.puzzles.matched', 'data_import.flow.puzzles.searching'], 5 => ['data_import.flow.add.nothing_step']],
            null, null, 4, null,
        ];

        yield 'puzzles kept just for the player or not imported leave nothing waiting' => [
            ['pairing' => new ImportPairingSummary(786, 600, 0, 0, 157, 29, 0, true), 'add' => ['rowsReady' => 700, 'rowsTaken' => 700, 'results' => 600, 'unlisted' => 100]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $open],
            [4 => ['data_import.flow.puzzles.matched']],
            'data_import.flow.primary.check_and_add', 'data_import_add', 5, null,
        ];

        yield 'an Add is running: "See how far it is", whatever else waits' => [
            [
                'sheets' => [self::sheet(questions: 1, rowsWaiting: 10)],
                'add' => ['runningBatchId' => self::BATCH, 'latestBatchId' => self::BATCH, 'rowsReady' => 10, 'rowsTaken' => 10, 'results' => 10],
            ],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $open, 3 => $ahead, 4 => $done, 5 => $working],
            [5 => ['data_import.flow.add.running']],
            'data_import.flow.primary.follow', 'data_import_batch', 5, null,
        ];

        yield 'rows came back with questions after an Add' => [
            ['add' => ['latestBatchId' => self::BATCH, 'notAdded' => 31, 'rowsAdded' => 246, 'resultsAdded' => 246]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $open],
            [5 => ['data_import.flow.add.rows_wait']],
            'data_import.flow.primary.answer', 'data_import_not_added', 5, null,
        ];

        yield 'rows came back, but a step before the Add is open: that step first' => [
            ['pairing' => new ImportPairingSummary(10, 5, 5, 0, 0, 0, 0, true), 'add' => ['latestBatchId' => self::BATCH, 'notAdded' => 31, 'rowsAdded' => 246, 'undecided' => 40]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $open, 5 => $open],
            [],
            'data_import.flow.primary.puzzles', 'data_import_puzzles', 4, null,
        ];

        yield 'everything is added and nothing is left' => [
            ['add' => ['latestBatchId' => self::BATCH, 'rowsAdded' => 918, 'resultsAdded' => 918, 'lastAddedAt' => new DateTimeImmutable('2026-11-15 14:02:00')]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $done],
            [5 => ['data_import.flow.add.added_on']],
            'data_import.flow.primary.see_added', 'data_import_added', 5, null,
        ];

        yield 'adding is paused by the team: said calmly on step 5, and no button leads to an Add' => [
            ['add' => ['paused' => true, 'rowsReady' => 5, 'rowsTaken' => 5, 'results' => 5]],
            ImportFlow::STAGE_STEPS,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $open],
            [5 => ['data_import.flow.add.ready', 'data_import.add.paused']],
            null, null, 5, null,
        ];

        yield 'a finished import: read-only, "See what was added"' => [
            ['status' => DataImportStatus::Done, 'add' => ['canAdd' => false, 'latestBatchId' => self::BATCH, 'rowsAdded' => 471, 'resultsAdded' => 471, 'rowsReady' => 30, 'rowsTaken' => 30, 'results' => 30]],
            ImportFlow::STAGE_FINISHED,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $done],
            [5 => ['data_import.flow.add.added']],
            'data_import.flow.primary.see_added', 'data_import_added', null, null,
        ];

        yield 'a finished import that added nothing has no button' => [
            ['status' => DataImportStatus::Done, 'add' => ['canAdd' => false]],
            ImportFlow::STAGE_FINISHED,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $ahead],
            [],
            null, null, null, null,
        ];

        yield 'a file whose rows are gone: read-only like a finished one' => [
            ['add' => ['canAdd' => false, 'wiped' => true, 'latestBatchId' => self::BATCH, 'rowsAdded' => 12, 'resultsAdded' => 12]],
            ImportFlow::STAGE_FINISHED,
            [1 => $done, 2 => $done, 3 => $ahead, 4 => $done, 5 => $done],
            [],
            'data_import.flow.primary.see_added', 'data_import_added', null, null,
        ];
    }

    public function testTheCheckLeadsToTheColumnsScreenOfTheSheetThatNeedsIt(): void
    {
        $flow = self::flow([
            'sheets' => [self::sheet(), self::sheet(rowsUnderstood: 0, rowsWaiting: 72, checkFirst: 'no_header', sheetId: self::SHEET)],
            'add' => ['rowsReady' => 300, 'rowsTaken' => 300, 'results' => 300],
        ]);

        self::assertNotNull($flow->primary);
        self::assertSame(['importId' => self::IMPORT, 'sheetId' => self::SHEET], $flow->primary->parameters);
        self::assertSame(ImportFlow::CHECK, $flow->primary->step);
        // The step's own line leads there too, and counts the sheet as one thing waiting
        self::assertSame('data_import_sheet', $flow->steps[ImportFlow::CHECK]->route);
        self::assertSame(['importId' => self::IMPORT, 'sheetId' => self::SHEET], $flow->steps[ImportFlow::CHECK]->parameters);
        self::assertSame(1, $flow->steps[ImportFlow::CHECK]->waiting);
        // What is ready - the sheet we are sure about - can still be added now; nothing of the held sheet is in it
        self::assertSame(300, $flow->secondary?->label->count);
    }

    public function testAFinishedImportOpensNoStepAndAnOpenOneOpensThemAll(): void
    {
        $finished = self::flow(['status' => DataImportStatus::Done, 'add' => ['canAdd' => false]]);
        $open = self::flow([]);

        foreach ([2, 3, 4, 5] as $number) {
            self::assertNull($finished->steps[$number]->route, 'the lines of a finished import are read-only');
            self::assertNotNull($open->steps[$number]->route);
            self::assertSame(['importId' => self::IMPORT], $open->steps[$number]->parameters);
        }

        self::assertSame('data_import.flow.headline.finished', $finished->headline->key);
        self::assertSame('data_import.flow.headline.gone', self::flow(['add' => ['wiped' => true, 'canAdd' => false]])->headline->key);
        self::assertSame('data_import.flow.headline.read', $open->headline->key);
        self::assertSame(792, $open->headline->count, 'the rows the file was read with');
        // While rows are read the heading waits: "We read N rows" is not said above "Reading your rows… 0 of 40"
        $reading = self::flow(['sheets' => [new DataImportSheetOverview(position: 0, title: 'Log', hidden: false, rows: 41, columns: 5, kind: DataImportSheetKind::Results, mapped: true, parsed: false)]]);
        self::assertSame('data_import.flow.headline.reading', $reading->headline->key);
        // ... without the rows of an imported sheet that are no puzzle (its header row, a totals line)
        $withHeaders = self::flow(['sheets' => [new DataImportSheetOverview(position: 0, title: 'Log', hidden: false, rows: 41, columns: 5, kind: DataImportSheetKind::Results, mapped: true, parsed: true, rowsUnderstood: 40, rowsLeftOut: 1)]]);
        self::assertSame(791, $withHeaders->headline->count);
    }

    public function testFinishDiscardAndUndoAreOfferedAsTheImportStands(): void
    {
        $fresh = self::flow([]);
        self::assertTrue($fresh->canDiscard, 'nothing was added yet: it can be discarded');
        self::assertFalse($fresh->canFinish);
        self::assertFalse($fresh->hasAdded);

        $added = self::flow(['add' => ['latestBatchId' => self::BATCH, 'rowsAdded' => 3, 'resultsAdded' => 3]]);
        self::assertFalse($added->canDiscard, 'it added something: it stays');
        self::assertTrue($added->canFinish);
        self::assertTrue($added->hasAdded);

        $running = self::flow(['add' => ['latestBatchId' => self::BATCH, 'runningBatchId' => self::BATCH]]);
        self::assertFalse($running->canFinish, 'not while an Add runs');

        $finished = self::flow(['status' => DataImportStatus::Done, 'add' => ['canAdd' => false, 'latestBatchId' => self::BATCH, 'rowsAdded' => 3]]);
        self::assertFalse($finished->canFinish);
    }

    /**
     * @param array<string, mixed> $given
     */
    #[DataProvider('provideContinues')]
    public function testContinueLeadsToTheNextScreenWithSomethingWaiting(array $given, string $after, string $route): void
    {
        $next = self::flow($given)->nextAfter($after); // @phpstan-ignore argument.type

        self::assertSame($route, $next['route']);
        self::assertSame(['importId' => self::IMPORT], $next['parameters']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function provideContinues(): iterable
    {
        $everything = [
            'sheets' => [self::sheet(questions: 1, rowsWaiting: 5, rowsNeedLook: 45)],
            'people' => ['people' => 2, 'players' => 0, 'unanswered' => 2],
            'pairing' => new ImportPairingSummary(10, 5, 5, 0, 0, 0, 0, true),
            'add' => ['undecided' => 40],
        ];

        yield 'after how the file was read: the rows that could not be read' => [$everything, 'check', 'data_import_to_fix'];
        yield 'after the rows: the people - never the rows again, though some are left' => [$everything, 'rows', 'data_import_people'];
        yield 'after the people: the puzzles' => [$everything, 'people', 'data_import_puzzles'];
        yield 'after the puzzles: step 5, which is never passed' => [$everything, 'puzzles', 'data_import_add'];
        yield 'a clean file: straight to step 5 from anywhere' => [[], 'check', 'data_import_add'];
        yield 'people not counted on the page are never a stop' => [['pairing' => new ImportPairingSummary(10, 5, 5, 0, 0, 0, 0, true), 'add' => ['undecided' => 40]], 'rows', 'data_import_puzzles'];
        yield 'an unknown screen: where you are' => [$everything, 'nonsense', 'data_import_detail'];
        yield 'a finished import: where you are' => [['status' => DataImportStatus::Done, 'add' => ['canAdd' => false]], 'check', 'data_import_detail'];
        yield 'a file that is not read: where you are' => [['status' => DataImportStatus::Reading], 'check', 'data_import_detail'];
    }

    /**
     * @param array{status?: DataImportStatus, sheets?: list<DataImportSheetOverview>, pairing?: ImportPairingSummary, add?: array<string, mixed>, people?: null|array{people: int, players: int, unanswered: int}} $given
     */
    private static function flow(array $given): ImportFlow
    {
        $import = new DataImportOverview(
            importId: self::IMPORT,
            fileName: 'my-puzzles.xlsx',
            fileSize: 61_000,
            status: $given['status'] ?? DataImportStatus::SettingUp,
            createdAt: new DateTimeImmutable('2026-11-14 10:00:00'),
            fileKept: true,
            fileRemovedAt: null,
            fileKeptUntil: new DateTimeImmutable('2026-12-14 10:00:00'),
            sheetsTotal: 2,
            sheetsRead: 2,
            rowsRead: 792,
        );

        return ImportFlow::of(
            $import,
            $given['sheets'] ?? [self::sheet(), self::sheet()],
            $given['pairing'] ?? new ImportPairingSummary(786, 786, 0, 0, 0, 0, 0, true),
            self::add($given['add'] ?? []),
            $given['people'] ?? null,
        );
    }

    private static function sheet(
        DataImportSheetKind $kind = DataImportSheetKind::Results,
        bool $mapped = true,
        bool $failed = false,
        int $questions = 0,
        int $rowsUnderstood = 300,
        int $rowsNeedLook = 0,
        int $rowsWaiting = 0,
        int $columnsToCheck = 0,
        bool $kindSure = true,
        null|string $checkFirst = null,
        string $sheetId = '',
    ): DataImportSheetOverview {
        return new DataImportSheetOverview(
            position: 0,
            title: 'Solo 500',
            hidden: false,
            rows: 396,
            columns: 6,
            kind: $kind,
            mapped: $mapped,
            waitsForAnAnswer: $questions > 0 || $checkFirst !== null,
            parsed: $mapped && $failed === false,
            rowsUnderstood: $rowsUnderstood,
            rowsNeedLook: $rowsNeedLook,
            failed: $failed,
            rowsWaiting: $rowsWaiting,
            questions: $questions,
            columnsToCheck: $columnsToCheck,
            kindSure: $kindSure,
            sheetId: $sheetId,
            checkFirst: $checkFirst,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function add(array $values): ImportAddSummary
    {
        $defaults = [
            'paused' => false,
            'canAdd' => true,
            'runningBatchId' => null,
            'latestBatchId' => null,
            'rowsReady' => 0,
            'rowsTaken' => 0,
            'results' => 0,
            'owned' => 0,
            'wishList' => 0,
            'later' => 0,
            'alreadyYours' => 0,
            'equalRows' => 0,
            'resultsLeftToday' => 2000,
            'unlisted' => 0,
            'needsExactDay' => 0,
            'undecided' => 0,
            'needLook' => 0,
            'sheetsNotSetUp' => 0,
            'notAdded' => 0,
            'rowsAdded' => 0,
            'resultsAdded' => 0,
            'collectionItemsAdded' => 0,
            'wishListItemsAdded' => 0,
            'lastAddedAt' => null,
            'wiped' => false,
        ];

        return new ImportAddSummary(...[...$defaults, ...$values]); // @phpstan-ignore argument.type
    }
}
