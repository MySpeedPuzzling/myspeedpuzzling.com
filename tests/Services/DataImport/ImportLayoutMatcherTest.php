<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\ImportLayoutMatcher;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportColumnRole;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportLayoutContent;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\RememberedLayout;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * docs/features/data-import/layouts.md - knowing a sheet's layout again, pure: the signature, the same headers in
 * another order, the 80 % rule, the answers a layout may and may not give, known exports, the remembered lookup
 * sheet. Every row here is made up.
 */
final class ImportLayoutMatcherTest extends TestCase
{
    private Kit $kit;
    private ImportLayoutMatcher $matcher;

    protected function setUp(): void
    {
        $this->kit = new Kit();
        $this->matcher = new ImportLayoutMatcher($this->kit->kindGuesser, $this->kit->decisions, $this->kit->columnRecogniser);
    }

    public function testTheSignatureIsTheHeaderRowAsItIsComparedAndASheetWithoutHeadersHasNone(): void
    {
        $one = $this->kit->analyser->analyse(Kit::sheet('Log', [1 => ['Puzzle', 'Pieces', 'Time'], 2 => ['Plum Orchard', 500, '1:02:03']]))->mapping;
        $same = $this->kit->analyser->analyse(Kit::sheet('Another name', [1 => ['PUZZLE ', 'pieces:', ' Time'], 2 => ['Chalk Cliffs', 1000, '2:10:11'], 3 => ['Tea House', 500, '0:55:01']]))->mapping;
        $moved = $this->kit->analyser->analyse(Kit::sheet('Log', [1 => ['Pieces', 'Puzzle', 'Time'], 2 => [500, 'Plum Orchard', '1:02:03']]))->mapping;

        self::assertSame(['puzzle#1', 'pieces#1', 'time#1'], ImportLayoutMatcher::headerKeys($one));
        self::assertNotNull(ImportLayoutMatcher::signature($one));
        self::assertStringStartsWith('L1:', (string) ImportLayoutMatcher::signature($one));
        // Not the sheet's name, not its rows, not how the headers are typed
        self::assertSame(ImportLayoutMatcher::signature($one), ImportLayoutMatcher::signature($same));
        // The order is part of it - the same headers in another order are found by the headers themselves
        self::assertNotSame(ImportLayoutMatcher::signature($one), ImportLayoutMatcher::signature($moved));

        // A header that stands there twice is numbered
        $twice = $this->kit->analyser->analyse(Kit::sheet('Log', [1 => ['Puzzle', 'Notes', 'Time', 'Notes'], 2 => ['Plum Orchard', 'a gift', '1:02:03', 'with Ana']]))->mapping;
        self::assertSame(['puzzle#1', 'notes#1', 'time#1', 'notes#2'], ImportLayoutMatcher::headerKeys($twice));

        $noHeader = $this->kit->analyser->analyse(Kit::sheet('Log', [1 => ['Plum Orchard', 500, '1:02:03'], 2 => ['Chalk Cliffs', 1000, '2:10:11'], 3 => ['Tea House', 500, '0:55:01']]))->mapping;
        self::assertNull($noHeader->table()?->headerRow);
        self::assertNull(ImportLayoutMatcher::signature($noHeader));
        self::assertNull(ImportLayoutMatcher::contentOf($noHeader, new ImportSheetOptions(), null));
    }

    public function testTheSameHeadersAreSetUpAsThePlayerDidWhereverTheirColumnsStandNow(): void
    {
        // The player said: "Mood" is the public comment, "Who" is not imported, and every row is a pair result
        $layout = $this->layout(
            Kit::sheet('2023', [1 => ['Puzzle', 'Pieces', 'Time', 'Mood', 'Who'], 2 => ['Plum Orchard', 500, '1:02:03', 'happy', 'Ana']]),
            ['mood#1' => ImportField::PublicComment, 'who#1' => null],
            new ImportSheetOptions(solvingType: PuzzlingType::Duo),
        );

        $moved = Kit::sheet('2024', [1 => ['Who', 'Time', 'Mood', 'Puzzle', 'Pieces'], 2 => ['Ana', '0:58:40', 'tired', 'Chalk Cliffs', 500]]);
        $proposal = $this->matcher->apply($moved->title, $this->kit->analyser->analyse($moved), [$layout], $moved->rows);

        self::assertEquals(new RememberedLayout(RememberedLayout::WHOLE, $layout->id, 'Log.xlsx - 2023'), $proposal->mapping->remembered);
        self::assertSame(
            [
                'Who' => [null, 'certain', 'remembered'],
                'Time' => ['time', 'certain', 'remembered'],
                'Mood' => ['public_comment', 'certain', 'remembered'],
                'Puzzle' => ['puzzle_name', 'certain', 'remembered'],
                'Pieces' => ['pieces', 'certain', 'remembered'],
            ],
            self::columns($proposal->mapping),
        );
        self::assertSame([DataImportSheetKind::Results, ColumnCertainty::Certain, DataImportRowMeaning::Result], [$proposal->mapping->kind, $proposal->mapping->kindCertainty, $proposal->mapping->rowMeaning]);
        self::assertSame(PuzzlingType::Duo, $proposal->options->solvingType);
        // What is applied is a mapping like any other
        self::assertEquals($proposal->mapping, ImportMapping::fromArray($proposal->mapping->toArray()));
    }

    public function testMostOfTheHeadersInCommonSetsUpThoseColumnsAndProposesTheRestAsUsual(): void
    {
        $headers = ['Puzzle', 'Pieces', 'Time', 'Mood', 'Who', 'Table', 'Lamp', 'Music', 'Snack', 'Weather'];
        $values = ['Plum Orchard', 500, '1:02:03', 'happy', 'Ana', 'oak', 'on', 'jazz', 'tea', 'rain'];
        $layout = $this->layout(Kit::sheet('2023', [1 => $headers, 2 => $values]), ['mood#1' => ImportField::PublicComment, 'weather#1' => ImportField::SolvedWith]);

        // One column is gone ("Weather"), one is new ("Brand"): 9 of 10 headers in common
        $next = Kit::sheet('2024', [1 => ['Puzzle', 'Pieces', 'Time', 'Mood', 'Who', 'Table', 'Lamp', 'Music', 'Snack', 'Brand'], 2 => ['Chalk Cliffs', 500, '0:58:40', 'tired', 'Ana', 'oak', 'off', 'none', 'cake', 'Trefl']]);
        $own = $this->kit->analyser->analyse($next);
        $proposal = $this->matcher->apply($next->title, $own, [$layout], $next->rows);
        $columns = self::columns($proposal->mapping);

        self::assertSame(RememberedLayout::PARTLY, $proposal->mapping->remembered?->how);
        self::assertSame(['public_comment', 'certain', 'remembered'], $columns['Mood']);
        self::assertSame(['time', 'certain', 'remembered'], $columns['Time']);
        // The new column is proposed like for anybody; the missing one is nobody's problem
        self::assertSame(self::columns($own->mapping)['Brand'], $columns['Brand']);
        self::assertSame('brand', $columns['Brand'][0]);
        self::assertArrayNotHasKey('Weather', $columns);
        self::assertSame(DataImportSheetKind::Results, $proposal->mapping->kind);

        // Seven of ten: another sheet - nothing of the layout is applied
        $other = Kit::sheet('2025', [1 => ['Puzzle', 'Pieces', 'Time', 'Mood', 'Who', 'Table', 'Lamp', 'Brand', 'Shop', 'Price'], 2 => ['Tea House', 500, '0:51:12', 'calm', 'Ana', 'oak', 'on', 'Trefl', 'Online', 12]]);
        $untouched = $this->kit->analyser->analyse($other);
        self::assertSame($untouched, $this->matcher->apply($other->title, $untouched, [$layout]));
        self::assertNull(ImportLayoutMatcher::matching($untouched->mapping, [$layout]));
    }

    public function testWhatASheetIsNeverSpreadsToASheetOfAnotherNameThatSaysOtherwise(): void
    {
        $headers = ['Puzzle', 'Pieces', 'Time'];
        // On the tab "Pairs" the player said: every row is a pair result
        $pairs = $this->layout(Kit::sheet('Pairs', [1 => $headers, 2 => ['Plum Orchard', 500, '1:02:03']]), [], new ImportSheetOptions(solvingType: PuzzlingType::Duo));

        foreach (['Solo', 'Teams', 'Pairs with Ana'] as $title) {
            $sheet = Kit::sheet($title, [1 => $headers, 2 => ['Chalk Cliffs', 500, '0:58:40']]);
            $proposal = $this->matcher->apply($title, $this->kit->analyser->analyse($sheet), [$pairs], $sheet->rows);

            // The columns follow - "every row is a pair" does not, and the sheet says that it was left alone
            self::assertSame(RememberedLayout::WHOLE, $proposal->mapping->remembered?->how, $title);
            self::assertNull($proposal->options->solvingType, $title);
            self::assertTrue($proposal->mapping->remembered->meaningKept, $title);
        }

        // A tab of that name in a later file (or "Pairs 2025" beside "Pairs 2024") is the same tab: the player's word holds
        foreach (['Pairs', 'pairs 2025'] as $title) {
            $sheet = Kit::sheet($title, [1 => $headers, 2 => ['Chalk Cliffs', 500, '0:58:40']]);
            $proposal = $this->matcher->apply($title, $this->kit->analyser->analyse($sheet), [$pairs], $sheet->rows);
            self::assertSame([PuzzlingType::Duo, false], [$proposal->options->solvingType, $proposal->mapping->remembered?->meaningKept], $title);
        }

        // "Collection" and "Wishlist" with the same header row: confirming one never makes the other its kind
        $list = ['Puzzle', 'Brand', 'Pieces'];
        $collection = Kit::sheet('Collection', [1 => $list, 2 => ['Plum Orchard', 'Trefl', 500]]);
        $wishlist = Kit::sheet('Wishlist', [1 => $list, 2 => ['Night Harbour', 'Jumbo', 1000]]);
        self::assertSame([DataImportSheetKind::Owned, DataImportSheetKind::WishList], [$this->kit->analyser->analyse($collection)->mapping->kind, $this->kit->analyser->analyse($wishlist)->mapping->kind]);

        $wishes = $this->matcher->apply('Wishlist', $this->kit->analyser->analyse($wishlist), [$this->layout($collection, [])], $wishlist->rows)->mapping;
        self::assertSame([DataImportSheetKind::WishList, DataImportRowMeaning::WishList, true], [$wishes->kind, $wishes->rowMeaning, $wishes->remembered?->meaningKept]);
        $owned = $this->matcher->apply('Collection', $this->kit->analyser->analyse($collection), [$this->layout($wishlist, [])], $collection->rows)->mapping;
        self::assertSame([DataImportSheetKind::Owned, DataImportRowMeaning::Owned, true], [$owned->kind, $owned->rowMeaning, $owned->remembered?->meaningKept]);

        // Where the sheet says the same by itself there is nothing to keep apart
        $other = Kit::sheet('Shelf', [1 => $list, 2 => ['Tea House', 'Jumbo', 1000]]);
        $shelf = $this->matcher->apply('Shelf', $this->kit->analyser->analyse($other), [$this->layout($collection, [])], $other->rows)->mapping;
        self::assertSame([DataImportSheetKind::Owned, ColumnCertainty::Certain, false], [$shelf->kind, $shelf->kindCertainty, $shelf->remembered?->meaningKept]);
    }

    public function testARememberedColumnIsCertainOnlyWhenTheSheetsOwnValuesGoWithIt(): void
    {
        // The player said: "When" is the day it was solved, "Lap" is the time
        $layout = $this->layout(
            Kit::sheet('Log', [1 => ['Puzzle', 'Pieces', 'When', 'Lap'], 2 => ['Plum Orchard', 500, '2026-01-10', '1:02:03']]),
            ['when#1' => ImportField::SolvedOn, 'lap#1' => ImportField::Time],
        );

        $fitting = Kit::sheet('Log', [1 => ['Puzzle', 'Pieces', 'When', 'Lap'], 2 => ['Chalk Cliffs', 500, '2026-02-08', '0:58:40'], 3 => ['Tea House', 500, '2026-02-09', '0:51:12']]);
        $columns = self::columns($this->matcher->apply('Log', $this->kit->analyser->analyse($fitting), [$layout], $fitting->rows)->mapping);
        self::assertSame([['solved_on', 'certain', 'remembered'], ['time', 'certain', 'remembered']], [$columns['When'], $columns['Lap']]);

        // The same headers over other values: "When" holds words, "Lap" holds names - kept as the player said, but
        // as columns to check, never as something we are sure of
        $other = Kit::sheet('Log', [1 => ['Puzzle', 'Pieces', 'When', 'Lap'], 2 => ['Chalk Cliffs', 500, 'in the morning', 'Ana'], 3 => ['Tea House', 500, 'after dinner', 'Ben']]);
        $columns = self::columns($this->matcher->apply('Log', $this->kit->analyser->analyse($other), [$layout], $other->rows)->mapping);
        self::assertSame([['solved_on', 'probable', 'remembered'], ['time', 'probable', 'remembered']], [$columns['When'], $columns['Lap']]);
        self::assertSame(['puzzle_name', 'certain', 'remembered'], $columns['Puzzle']);

        // Values the recogniser itself reads as something else: remembered "Time" over a column of dates
        $dates = Kit::sheet('Log', [1 => ['Puzzle', 'Pieces', 'When', 'Lap'], 2 => ['Chalk Cliffs', 500, '2026-02-08', Kit::date('2026-02-08')], 3 => ['Tea House', 500, '2026-02-09', Kit::date('2026-02-09')]]);
        $mapping = $this->matcher->apply('Log', $this->kit->analyser->analyse($dates), [$layout], $dates->rows)->mapping;
        self::assertSame(['time', 'probable', 'remembered'], self::columns($mapping)['Lap']);
    }

    public function testAColumnProposedBesideARememberedOneNeverTakesTheSameField(): void
    {
        $headers = ['Puzzle', 'Pieces', 'Lap', 'Mood', 'Who', 'Table', 'Lamp', 'Music', 'Snack', 'Weather'];
        // The player said the time stands in "Lap"
        $layout = $this->layout(Kit::sheet('2023', [1 => $headers, 2 => ['Plum Orchard', 500, '1:02:03', 'happy', 'Ana', 'oak', 'on', 'jazz', 'tea', 'rain']]), ['lap#1' => ImportField::Time]);

        $next = Kit::sheet('2024', [1 => ['Puzzle', 'Pieces', 'Lap', 'Mood', 'Who', 'Table', 'Lamp', 'Music', 'Snack', 'Time'], 2 => ['Chalk Cliffs', 500, '0:58:40', 'tired', 'Ana', 'oak', 'off', 'none', 'cake', '0:58:40']]);
        $columns = self::columns($this->matcher->apply($next->title, $this->kit->analyser->analyse($next), [$layout], $next->rows)->mapping);

        self::assertSame(['time', 'certain', 'remembered'], $columns['Lap']);
        self::assertSame([null, 'unknown', 'duplicate'], $columns['Time']);
    }

    public function testARememberedAnswerIsGivenOnlyToAQuestionTheSheetAsksAndNeverAgainstItsOwnValues(): void
    {
        // Two times nobody can read two ways for sure: 500 pieces in 45 minutes - or in 45 hours
        $sheet = Kit::sheet('Autumn', [1 => ['Puzzle', 'Pieces', 'Time'], 2 => ['Plum Orchard', 500, '45:10'], 3 => ['Chalk Cliffs', 500, '52:31']]);
        $mapping = $this->kit->analyser->analyse($sheet)->mapping;
        $rows = static fn (): array => $sheet->rows;

        [, $asked] = $this->matcher->settle($mapping, new ImportSheetOptions(), $sheet->title, $rows, null);
        self::assertCount(1, $asked);
        self::assertSame(['duration_shape.two_part_text', 'minutes_seconds'], [$asked[0]->id(), $asked[0]->suggested]);

        // "Minutes and seconds" last time: nothing is asked, and the answer stands as the player's
        $minutes = new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::MinutesSeconds], durationsColumn: 'time#1');
        [$options, $questions] = $this->matcher->settle($mapping, new ImportSheetOptions(), $sheet->title, $rows, $minutes);
        self::assertSame([], $questions);
        self::assertSame(['rule' => DurationReadingRule::MinutesSeconds, 'by' => ImportSheetOptions::BY_PLAYER], $options->durations[DurationShape::TwoPartText->value]);

        // "Hours and minutes" last time, but this sheet's own values point the other way: asked again
        $hours = new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::HoursMinutes], durationsColumn: 'time#1');
        [$options, $questions] = $this->matcher->settle($mapping, new ImportSheetOptions(), $sheet->title, $rows, $hours);
        self::assertCount(1, $questions);
        self::assertSame([], $options->durations);

        $keys = ImportLayoutMatcher::keysByColumn($mapping);
        // A reading the sheet's values rule out is not among the choices - never given
        self::assertSame([], ImportLayoutMatcher::answers($asked, new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::Seconds], durationsColumn: 'time#1'), $keys));
        // An answer about another shape answers nothing here; the year is never a layout's
        self::assertSame([], ImportLayoutMatcher::answers($asked, new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::BareNumber->value => DurationReadingRule::Minutes], DateOrder::DayMonthYear, durationsColumn: 'time#1', datesColumn: 'time#1'), $keys));

        // An answer given for another column says nothing about this one: "Minutes and seconds" was said about a
        // column called "Lap" - this sheet's "Time" is asked about like anybody's
        $ofAnotherColumn = new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::MinutesSeconds], durationsColumn: 'lap#1');
        self::assertSame([], ImportLayoutMatcher::answers($asked, $ofAnotherColumn, $keys));
        [$options, $questions] = $this->matcher->settle($mapping, new ImportSheetOptions(), $sheet->title, $rows, $ofAnotherColumn);
        self::assertSame([1, []], [count($questions), $options->durations]);
        // ... and an answer without its column (nothing says which it was) is nobody's
        self::assertSame([], ImportLayoutMatcher::answers($asked, new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::MinutesSeconds]), $keys));

        // What the rows settle by themselves is the rows' - a layout is not asked
        $clear = Kit::sheet('Clear', [1 => ['Puzzle', 'Pieces', 'Time'], 2 => ['Plum Orchard', 500, '1:02:03']]);
        [$options, $questions] = $this->matcher->settle($this->kit->analyser->analyse($clear)->mapping, new ImportSheetOptions(), $clear->title, static fn (): array => $clear->rows, $hours);
        self::assertSame([[], []], [$questions, $options->durations]);
    }

    public function testOnlyThePlayersOwnAnswersAreRememberedAndNeverTheYear(): void
    {
        $mapping = $this->kit->analyser->analyse(Kit::sheet('2023', [1 => ['Puzzle', 'Pieces', 'Time', 'Date'], 2 => ['Plum Orchard', 500, '45:10', '3/4/2023']]))->mapping;
        $options = (new ImportSheetOptions())
            ->withDuration(DurationShape::TwoPartText, DurationReadingRule::MinutesSeconds, ImportSheetOptions::BY_PLAYER)
            ->withDuration(DurationShape::BareNumber, DurationReadingRule::Minutes, ImportSheetOptions::BY_ROWS)
            ->withDateOrder(DateOrder::DayMonthYear, ImportSheetOptions::BY_PLAYER)
            ->withYear(2023, ImportSheetOptions::BY_PLAYER)
            ->withCollectionLabel('Keep pile');

        $content = ImportLayoutMatcher::contentOf($mapping, $options, null, 'Log');
        self::assertNotNull($content);
        self::assertSame([DurationShape::TwoPartText->value => DurationReadingRule::MinutesSeconds], $content->durations);
        // Each answer with the column it was given for, and the name of the sheet
        self::assertSame(['time#1', 'date#1', 'Log'], [$content->durationsColumn, $content->datesColumn, $content->title]);
        self::assertSame(DateOrder::DayMonthYear, $content->dateOrder);
        self::assertNull($content->shortDates);
        // Stored and read back the same - and no year, no collection, no place of the table anywhere in it
        self::assertEquals($content, ImportLayoutContent::fromArray($content->toArray()));
        $stored = (string) json_encode($content->toArray());
        self::assertStringNotContainsString('2023', $stored);
        self::assertStringNotContainsString('Keep pile', $stored);
        self::assertStringNotContainsString('Plum Orchard', $stored);

        // A sheet the player left out is not remembered
        self::assertNull(ImportLayoutMatcher::contentOf($mapping->withKind(DataImportSheetKind::SomebodyElse, ColumnCertainty::Certain), $options, null));
        self::assertNull(ImportLayoutMatcher::contentOf($mapping->withKind(DataImportSheetKind::NotPuzzles, ColumnCertainty::Certain), $options, null));
    }

    public function testAKnownExportStaysTheExportUnlessThePlayerChangedItsMapping(): void
    {
        $export = ImportShapes::puzzleTrackerExport()[0];
        $own = $this->kit->analyser->analyse($export);
        self::assertSame(KnownExportLayout::PUZZLE_TRACKER, $own->mapping->layout);

        // A layout saved from that very header row, with "Artist" turned off - but not marked as a change of the export
        $content = ImportLayoutMatcher::contentOf($own->mapping, $own->options, null);
        self::assertNotNull($content);
        $columns = $content->columns;
        $columns['artist#1'] = ['field' => null, 'readyMade' => null];
        $content = new ImportLayoutContent($content->kind, $content->rowMeaning, $columns, title: $export->title);
        $saved = static fn (bool $changes): SavedImportLayout => new SavedImportLayout('0193a000-0000-7000-8000-000000000001', 'My export', (string) ImportLayoutMatcher::signature($own->mapping), ImportLayoutMatcher::headerKeys($own->mapping), $content, $changes, TestClock::now());

        self::assertSame($own, $this->matcher->apply('', $own, [$saved(false)]));

        // The player did change it: theirs wins - and it is still that export (its row ids, its tags, its words)
        $changed = $this->matcher->apply('', $own, [$saved(true)]);
        self::assertSame(RememberedLayout::WHOLE, $changed->mapping->remembered?->how);
        self::assertSame(KnownExportLayout::PUZZLE_TRACKER, $changed->mapping->layout);
        self::assertSame([null, 'certain', 'remembered'], self::columns($changed->mapping)['Artist']);
        self::assertNotNull($changed->mapping->columnWithRole(ImportColumnRole::SourceRowId));
        self::assertSame($own->valueAnswers, $changed->valueAnswers);
    }

    public function testThePuzzleListIsFoundAgainByItsHeaderRowAndItsNameNeverByAnId(): void
    {
        $list = Kit::sheet('My puzzles', [1 => ['Puzzle', 'Brand', 'Pieces'], 2 => ['Plum Orchard', 'Trefl', 500]]);
        $results = Kit::sheet('Log', [1 => ['Puzzle', 'Date', 'Time'], 2 => ['Somewhere Else', '2026-01-10', '1:02:03']]);
        $listMapping = $this->kit->analyser->analyse($list)->mapping;
        $layout = $this->layout($results, [], lookup: ['signature' => (string) ImportLayoutMatcher::signature($listMapping), 'title' => 'My puzzles']);

        // The names of this file's results are not on the list (yet): the workbook's own names find nothing
        $proposals = [
            'results-id' => $this->matcher->apply($results->title, $this->kit->analyser->analyse($results), [$layout], $results->rows),
            'list-id' => $this->kit->analyser->analyse($list),
            'other-id' => $this->kit->analyser->analyse(Kit::sheet('Sold', [1 => ['Puzzle', 'Brand', 'Pieces'], 2 => ['Tea House', 'Jumbo', 1000]])),
        ];
        $linked = ImportLayoutMatcher::withRememberedLookups($proposals, ['results-id' => 'Log', 'list-id' => 'My puzzles', 'other-id' => 'Sold'], [$layout]);

        self::assertSame('list-id', $linked['results-id']->options->lookupSheetId);
        self::assertSame(DataImportSheetKind::PuzzleList, $linked['list-id']->mapping->kind);
        self::assertSame(DataImportSheetKind::Owned, $linked['other-id']->mapping->kind);
        self::assertStringNotContainsString('list-id', (string) json_encode($layout->content->toArray()));

        // Another name: not that list
        $renamed = ImportLayoutMatcher::withRememberedLookups($proposals, ['results-id' => 'Log', 'list-id' => 'Something else', 'other-id' => 'Sold'], [$layout]);
        self::assertNull($renamed['results-id']->options->lookupSheetId);
    }

    /**
     * A layout as it is saved when the player confirms the sheet with some columns changed.
     *
     * @param array<string, null|ImportField> $changed header key => what the player said the column is
     * @param null|array{signature: string, title: string} $lookup
     */
    private function layout(SheetSample $sheet, array $changed, null|ImportSheetOptions $options = null, null|array $lookup = null): SavedImportLayout
    {
        $mapping = $this->kit->analyser->analyse($sheet)->mapping;
        $keys = ImportLayoutMatcher::keysByColumn($mapping);
        $columns = array_map(
            static fn (MappedColumn $column): MappedColumn => array_key_exists($keys[$column->column] ?? '', $changed)
                ? new MappedColumn($column->column, $column->header, $changed[$keys[$column->column]], ColumnCertainty::Certain, MappedColumn::BY_PLAYER)
                : $column,
            $mapping->columns,
        );
        $confirmed = new ImportMapping($mapping->kind, ColumnCertainty::Certain, $mapping->rowMeaning, $mapping->regions, $mapping->region, $columns);
        $content = ImportLayoutMatcher::contentOf($confirmed, $options ?? new ImportSheetOptions(), $lookup, $sheet->title);
        self::assertNotNull($content);

        return new SavedImportLayout(
            '0193a000-0000-7000-8000-000000000001',
            'Log.xlsx - ' . $sheet->title,
            (string) ImportLayoutMatcher::signature($confirmed),
            ImportLayoutMatcher::headerKeys($confirmed),
            $content,
            false,
            TestClock::now(),
        );
    }

    /**
     * @return array<string, array{null|string, string, string}> header => field, certainty, reason
     */
    private static function columns(ImportMapping $mapping): array
    {
        $columns = [];

        foreach ($mapping->columns as $column) {
            $columns[$column->header] = [$column->field?->value, $column->certainty->value, $column->reason];
        }

        return $columns;
    }
}
