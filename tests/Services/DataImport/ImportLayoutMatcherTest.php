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
        $this->matcher = new ImportLayoutMatcher($this->kit->kindGuesser, $this->kit->decisions);
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

        $moved = Kit::sheet('Spring', [1 => ['Who', 'Time', 'Mood', 'Puzzle', 'Pieces'], 2 => ['Ana', '0:58:40', 'tired', 'Chalk Cliffs', 500]]);
        $proposal = $this->matcher->apply($moved->title, $this->kit->analyser->analyse($moved), [$layout]);

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
        $proposal = $this->matcher->apply($next->title, $own, [$layout]);
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

    public function testAColumnProposedBesideARememberedOneNeverTakesTheSameField(): void
    {
        $headers = ['Puzzle', 'Pieces', 'Finished', 'Mood', 'Who', 'Table', 'Lamp', 'Music', 'Snack', 'Weather'];
        // The player said the time stands in "Finished"
        $layout = $this->layout(Kit::sheet('2023', [1 => $headers, 2 => ['Plum Orchard', 500, '1:02:03', 'happy', 'Ana', 'oak', 'on', 'jazz', 'tea', 'rain']]), ['finished#1' => ImportField::Time]);

        $next = Kit::sheet('2024', [1 => ['Puzzle', 'Pieces', 'Finished', 'Mood', 'Who', 'Table', 'Lamp', 'Music', 'Snack', 'Time'], 2 => ['Chalk Cliffs', 500, '0:58:40', 'tired', 'Ana', 'oak', 'off', 'none', 'cake', '0:58:40']]);
        $columns = self::columns($this->matcher->apply($next->title, $this->kit->analyser->analyse($next), [$layout])->mapping);

        self::assertSame(['time', 'certain', 'remembered'], $columns['Finished']);
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
        $minutes = new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::MinutesSeconds]);
        [$options, $questions] = $this->matcher->settle($mapping, new ImportSheetOptions(), $sheet->title, $rows, $minutes);
        self::assertSame([], $questions);
        self::assertSame(['rule' => DurationReadingRule::MinutesSeconds, 'by' => ImportSheetOptions::BY_PLAYER], $options->durations[DurationShape::TwoPartText->value]);

        // "Hours and minutes" last time, but this sheet's own values point the other way: asked again
        $hours = new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::HoursMinutes]);
        [$options, $questions] = $this->matcher->settle($mapping, new ImportSheetOptions(), $sheet->title, $rows, $hours);
        self::assertCount(1, $questions);
        self::assertSame([], $options->durations);

        // A reading the sheet's values rule out is not among the choices - never given
        self::assertSame([], ImportLayoutMatcher::answers($asked, new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::TwoPartText->value => DurationReadingRule::Seconds])));
        // An answer about another shape answers nothing here; the year is never a layout's
        self::assertSame([], ImportLayoutMatcher::answers($asked, new ImportLayoutContent(DataImportSheetKind::Results, DataImportRowMeaning::Result, [], [DurationShape::BareNumber->value => DurationReadingRule::Minutes], DateOrder::DayMonthYear)));

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

        $content = ImportLayoutMatcher::contentOf($mapping, $options, null);
        self::assertNotNull($content);
        self::assertSame([DurationShape::TwoPartText->value => DurationReadingRule::MinutesSeconds], $content->durations);
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
        $content = new ImportLayoutContent($content->kind, $content->rowMeaning, $columns);
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
            'results-id' => $this->matcher->apply($results->title, $this->kit->analyser->analyse($results), [$layout]),
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
        $content = ImportLayoutMatcher::contentOf($confirmed, $options ?? new ImportSheetOptions(), $lookup);
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
