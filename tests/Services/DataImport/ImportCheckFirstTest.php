<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\ColumnRecogniser;
use SpeedPuzzling\Web\Services\DataImport\ImportCheckFirst;
use SpeedPuzzling\Web\Services\DataImport\PendingQuestionRows;
use SpeedPuzzling\Web\Services\DataImport\StoredCells;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\DataRegionSource;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;
use SpeedPuzzling\Web\Value\DataImport\RememberedLayout;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * docs/features/data-import/columns.md "Check first", "The brand by its values": a sheet whose reading is a guess
 * asks for the player's eye before anything is built on it - and a sheet without a header row is read as well as
 * its values allow. Every puzzle, brand and person here is made up; the brands "of the catalogue" are a closure.
 */
final class ImportCheckFirstTest extends TestCase
{
    /** Brands the made-up catalogue knows, as ImportBrandResolver would answer for them */
    private const array BRANDS = ['Northwind Puzzles' => 'b-1', 'Paper Lantern' => 'b-2', 'Oakfield' => 'b-3'];

    private Kit $kit;

    protected function setUp(): void
    {
        $this->kit = new Kit();
    }

    public function testASheetWithoutAHeaderRowIsReadFromItsValuesAndStillAsksForTheCheck(): void
    {
        // The shape of the file a player reported: no header row - date, brand, pieces, name, artist, year, time, notes
        $mapping = $this->analysed(self::headerless());
        $table = $mapping->table();

        self::assertNotNull($table);
        self::assertSame([DataRegionSource::NoHeader, null, 1], [$table->source, $table->headerRow, $table->firstRow]);
        self::assertSame(DataImportSheetKind::Results, $mapping->kind);

        $read = [];

        foreach ($mapping->columns as $column) {
            $read[$column->column] = [$column->field, $column->certainty, $column->reason];
        }

        self::assertSame([ImportField::SolvedOn, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[1]);
        self::assertSame([ImportField::Brand, ColumnCertainty::Probable, MappedColumn::BY_BRAND_VALUES], $read[2]);
        self::assertSame([ImportField::Pieces, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[3]);
        self::assertSame([ImportField::PuzzleName, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[4]);
        self::assertSame([ImportField::Time, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[7]);
        // The artist, the year and the notes are nobody's guess
        self::assertSame([null, null, null], [$read[5][0], $read[6][0], $read[8][0]]);

        // Recognised - and still a guess: the player is asked to look first
        self::assertSame(ImportCheckFirst::BECAUSE_NO_HEADER, ImportCheckFirst::reason($mapping));
        $asked = ImportCheckFirst::asked($mapping);
        self::assertTrue(ImportCheckFirst::isAsked($asked));
        self::assertSame(ImportQuestionType::CheckColumns, $asked->questions[0]->type);
        self::assertSame([ImportCheckFirst::YES], $asked->questions[0]->choices);
        // Never asked twice on one mapping, and the stored shape reads back
        self::assertCount(1, ImportCheckFirst::asked($asked)->questions);
        self::assertTrue(ImportCheckFirst::isAsked(ImportMapping::fromArray($asked->toArray())));
    }

    public function testADateTypedAsTextIsSolvedOnOnlyWithoutAHeaderRow(): void
    {
        $rows = [];

        foreach (['03.01.2024', '14.01.2024', '02.02.2024', '2024-02-19', '7 March 2024'] as $index => $day) {
            $rows[] = ['Harbour at Dusk number ' . $index, $day, Kit::duration(3000 + $index * 100)];
        }

        // No header row: whole days in every row are as plain as a date cell
        $headerless = $this->analysed(Kit::sheet('Sheet1', self::from(1, $rows)));
        self::assertSame([2, ColumnCertainty::Probable, MappedColumn::BY_VALUES], [$headerless->column(ImportField::SolvedOn)?->column, $headerless->column(ImportField::SolvedOn)?->certainty, $headerless->column(ImportField::SolvedOn)?->reason]);

        // Under a header nobody knows the column may be any date (bought on, released on): the player says it
        $headed = $this->analysed(Kit::sheet('Sheet1', [1 => ['Puzzle', 'Zq', 'Time'], ...self::from(2, $rows)]));
        self::assertNull($headed->column(ImportField::SolvedOn));

        // Bare years, short dates and times are no column of days
        foreach ([[2019, 2020, 2021, 2022, 2023], ['Jan 23', 'Feb 23', 'Mar 23', 'Apr 23', 'May 23'], ['1:02:03', '0:45:10', '2:10:00', '1:15:20', '0:59:59']] as $values) {
            $other = $this->analysed(Kit::sheet('Sheet1', self::from(1, array_map(
                static fn (int $index): array => ['Harbour at Dusk number ' . $index, $values[$index], 500],
                range(0, 4),
            ))));
            self::assertNull($other->column(ImportField::SolvedOn), (string) $values[0]);
        }
    }

    public function testACsvWithoutAHeaderRowIsReadThoughEveryCellIsText(): void
    {
        // A CSV: no header row, and every cell - the pieces, the date, the time - is a text
        $rows = [];

        foreach (self::headerless()->rows as $number => $cells) {
            $rows[$number] = array_map(static fn (array $cell): string => StoredCells::shown($cell), array_values($cells));
        }

        $mapping = $this->analysed(Kit::sheet('log', $rows));
        $read = [];

        foreach ($mapping->columns as $column) {
            $read[$column->column] = [$column->field, $column->certainty, $column->reason];
        }

        self::assertSame([ImportField::SolvedOn, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[1]);
        self::assertSame([ImportField::Brand, ColumnCertainty::Probable, MappedColumn::BY_BRAND_VALUES], $read[2]);
        self::assertSame([ImportField::Pieces, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[3]);
        self::assertSame([ImportField::PuzzleName, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[4]);
        // The time, typed "0:50:00" … "2:07:11": the reader's own reading makes the column the time
        self::assertSame([ImportField::Time, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[7]);
        // The year stays nobody's guess - a column of plain numbers is no time
        self::assertNull($read[6][0]);
        self::assertSame(DataImportSheetKind::Results, $mapping->kind);
        self::assertSame(ImportCheckFirst::BECAUSE_NO_HEADER, ImportCheckFirst::reason($mapping));
    }

    public function testALargeSheetWithoutAHeaderRowWhoseFewPuzzlesRepeatIsStillResultsAndHeldForTheCheck(): void
    {
        // The shape a player reported: 4,000 rows of a CSV without a header row - date, brand, pieces, name, time -
        // made of five puzzles solved again and again. The recognisers see the first rows only
        $mapping = $this->analysed(self::repeatedLog(DataImportLimits::SAMPLE_ROWS, 4000, 5));
        $read = [];

        foreach ($mapping->columns as $column) {
            $read[$column->column] = [$column->field, $column->certainty, $column->reason];
        }

        self::assertSame([ImportField::SolvedOn, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[1]);
        self::assertSame([ImportField::Brand, ColumnCertainty::Probable, MappedColumn::BY_BRAND_VALUES], $read[2]);
        self::assertSame([ImportField::Pieces, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[3]);
        // Five names in 300 rows: not "mostly different", and still the only text of the sheet that can be the name
        self::assertSame([ImportField::PuzzleName, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[4]);
        self::assertSame([ImportField::Time, ColumnCertainty::Probable, MappedColumn::BY_VALUES], $read[5]);
        self::assertSame([DataImportSheetKind::Results, ColumnCertainty::Probable], [$mapping->kind, $mapping->kindCertainty]);
        self::assertSame(ImportCheckFirst::BECAUSE_NO_HEADER, ImportCheckFirst::reason($mapping));
        self::assertTrue(ImportCheckFirst::isAsked(ImportCheckFirst::asked($mapping)));

        // The same without the catalogue's word on the brands: two repeated texts - the left one is taken (a wrong
        // guess here), and the sheet is results and held all the same: the player puts it right on the check
        $unbranded = $this->analysed(self::repeatedLog(DataImportLimits::SAMPLE_ROWS, 4000, 5), []);
        self::assertSame(2, $unbranded->column(ImportField::PuzzleName)?->column);
        self::assertSame(DataImportSheetKind::Results, $unbranded->kind);
        self::assertSame(ImportCheckFirst::BECAUSE_NO_HEADER, ImportCheckFirst::reason($unbranded));
    }

    public function testTheNameOfAHeaderlessSheetIsTheLeftmostTextNotTheMostVariedAndNeverAColumnThatLostItsField(): void
    {
        $names = ['Harbour at Dusk', 'Lanterns in the Rain', 'Orchard', 'Market Day', 'Kites'];

        // Date, time, five names that repeat, and a short comment that differs in every row: the names stand before
        // the notes - the comment is not the puzzle, though it says more different things
        $commented = $this->analysed(Kit::sheet('log', self::from(1, array_map(
            static fn (int $index): array => [sprintf('2024-03-%02d', $index + 1), sprintf('1:%02d:%02d', 4 + $index, 10 + $index), $names[$index % 5], 'note ' . $index],
            range(0, 11),
        ))), []);
        self::assertSame(3, $commented->column(ImportField::PuzzleName)?->column);
        self::assertSame(DataImportSheetKind::Results, $commented->kind);
        self::assertSame(ImportCheckFirst::BECAUSE_NO_HEADER, ImportCheckFirst::reason($commented));

        // Two columns of piece counts typed with their unit: the weaker one lost the field and is nobody's name,
        // though it is a text and stands left of the names
        $twice = $this->analysed(Kit::sheet('log', self::from(1, array_map(
            static fn (int $index): array => [sprintf('2024-03-%02d', $index + 1), ['1000 pcs', '500 pcs'][$index % 2], ['500 pcs', '1000 pcs', '2000 pcs'][$index % 3], $names[$index % 5], sprintf('1:%02d:%02d', 4 + $index, 10 + $index)],
            range(0, 11),
        ))), []);
        $fields = array_column(array_map(static fn (MappedColumn $column): array => [$column->column, $column->field], $twice->columns), 1, 0);
        self::assertContains(ImportField::Pieces, [$fields[2], $fields[3]]);
        self::assertContains(null, [$fields[2], $fields[3]]);
        self::assertSame(4, $twice->column(ImportField::PuzzleName)?->column);

        // Short codes ("A1", "B2") left of the names are no names: half of them are shorter than four characters
        $coded = $this->analysed(Kit::sheet('log', self::from(1, array_map(
            static fn (int $index): array => [sprintf('2024-03-%02d', $index + 1), ['A1', 'B2', 'C3'][$index % 3], $names[$index % 5], sprintf('1:%02d:%02d', 4 + $index, 10 + $index)],
            range(0, 11),
        ))), []);
        self::assertSame(3, $coded->column(ImportField::PuzzleName)?->column);
    }

    public function testOnAHeaderlessSheetOnlyATextThatSaysSomethingBecomesTheNameAndNumbersAloneStayNotPuzzles(): void
    {
        // A drill log without a header row: dates, times, a count - no text at all. Not puzzles, as before
        $drills = $this->analysed(Kit::sheet('drills', self::from(1, array_map(
            static fn (int $index): array => [sprintf('2024-03-%02d', $index + 1), sprintf('0:%02d:%02d', 4 + $index, 10 + $index), (string) (100 + $index * 7)],
            range(0, 11),
        ))));
        self::assertSame(DataImportSheetKind::NotPuzzles, $drills->kind);
        self::assertNull($drills->column(ImportField::PuzzleName));
        self::assertNull(ImportCheckFirst::reason($drills));

        // The same with one word in every row ("solo") and a yes / no column: neither is a puzzle's name
        $marked = $this->analysed(Kit::sheet('drills', self::from(1, array_map(
            static fn (int $index): array => [sprintf('2024-03-%02d', $index + 1), sprintf('0:%02d:%02d', 4 + $index, 10 + $index), 'solo', $index % 2 === 0 ? 'yes' : 'no'],
            range(0, 11),
        ))));
        self::assertSame(DataImportSheetKind::NotPuzzles, $marked->kind);

        // Texts and nothing of a puzzle list beside them (no time, date, pieces, brand, barcode): nobody's guess
        $words = $this->analysed(Kit::sheet('notes', self::from(1, array_map(
            static fn (int $index): array => [['Harbour', 'Orchard', 'Kites'][$index % 3], ['north', 'south'][$index % 2]],
            range(0, 11),
        ))));
        self::assertSame(DataImportSheetKind::NotPuzzles, $words->kind);

        // Under a found header row a repeated text is never made the name: only the header-less sheet is guessed
        $headed = $this->analysed(Kit::sheet('log', self::from(1, [
            ['When', 'Qzx', 'Took'],
            ...array_map(static fn (int $index): array => [Kit::date(sprintf('2024-03-%02d', $index + 1)), ['Harbour at Dusk', 'Lanterns in the Rain'][$index % 2], Kit::duration(3000 + $index)], range(0, 11)),
        ])));
        self::assertNull($headed->column(ImportField::PuzzleName));
    }

    public function testAHeaderlessLogOfFiveHundredRowsWithMostlyDifferentNamesIsReadAsBefore(): void
    {
        $mapping = $this->analysed(self::repeatedLog(DataImportLimits::SAMPLE_ROWS, 500, 430));

        self::assertSame(
            [1 => ImportField::SolvedOn, 2 => ImportField::Brand, 3 => ImportField::Pieces, 4 => ImportField::PuzzleName, 5 => ImportField::Time],
            array_column(array_map(static fn (MappedColumn $column): array => [$column->column, $column->field], $mapping->columns), 1, 0),
        );
        self::assertSame(DataImportSheetKind::Results, $mapping->kind);
        self::assertSame(ImportCheckFirst::BECAUSE_NO_HEADER, ImportCheckFirst::reason($mapping));
    }

    public function testATimeTypedAsTextIsTheTimeAlsoUnderAHeaderNobodyKnowsButNumbersDatesAndNotesAreNot(): void
    {
        $sheet = static fn (array $values): SheetSample => Kit::sheet('Sheet1', [1 => ['Puzzle', 'Pieces', 'Zq'], ...self::from(2, array_map(
            static fn (int $index): array => ['Harbour at Dusk number ' . $index, 500, $values[$index % count($values)]],
            range(0, 5),
        ))]);

        // ... with fractions of a second too: "30:20.23" is no number and no pieces column
        foreach ([['1:30:41', '0:45:10', '2:07:11'], ['45:10', '52:31', '38:02'], ['1h 30m', '45m 10s', '2h 5m'], ['0:30:20.23', '1:02:03.5', '0:41:07.90'], ['30:20.23', '41:07,90', '38:02.5']] as $times) {
            $time = $this->analysed($sheet($times))->column(ImportField::Time);
            self::assertSame([3, ColumnCertainty::Probable, MappedColumn::BY_VALUES], [$time?->column, $time?->certainty, $time?->reason], $times[0]);
            // Guessed from its values, on a results sheet: looked at before anything is built on it
            self::assertSame(ImportCheckFirst::BECAUSE_UNSURE, ImportCheckFirst::reason($this->analysed($sheet($times))));
        }

        // Bare numbers (a year, pieces, minutes without a unit), days, and times with words around them are no time column
        foreach ([['2019', '2020', '2021'], ['1000', '500', '2000'], ['90', '45', '127'], ['03.01.2024', '14.01.2024', '02.02.2024'], ['about 1:30 with Marta', 'ca 45 min, tired', 'fast, 38:02 or so']] as $values) {
            self::assertNull($this->analysed($sheet($values))->column(ImportField::Time), $values[0]);
        }
    }

    public function testATimeWithAFractionOfASecondIsTheSameTimeAsWithoutItAndIsNeverAskedAbout(): void
    {
        // Text, as every cell of a CSV is - on a sheet without a header row
        $texts = ['0:30:20.23', '30:20.23', '1:02:03.5', '30:20,23', '41:07.90', '0:41:07.5'];
        $sheets = $this->kit->workbook([Kit::sheet('log', self::from(1, array_map(
            static fn (int $index): array => ['Harbour at Dusk number ' . $index, '500', $texts[$index]],
            range(0, 5),
        )))]);
        $mapping = $sheets['log']['proposal']->mapping;

        self::assertSame(3, $mapping->column(ImportField::Time)?->column);
        // Two parts with a fraction are minutes:seconds - nothing is asked, and never 30 hours 20 minutes
        self::assertSame([], $mapping->questions);
        self::assertSame(
            [1820, 1820, 3723, 1820, 2467, 2467],
            array_values(array_map(static fn (ParsedImportRow $row): mixed => $row->parsed['seconds'] ?? null, $sheets['log']['rows'])),
        );

        // The same as cells: a duration ([h]:mm:ss.00) and a time of day (mm:ss.00), whose fraction the reader dropped
        $cells = $this->kit->workbook([Kit::sheet('log', self::from(1, array_map(
            static fn (int $index): array => ['Harbour at Dusk number ' . $index, 500, [Kit::duration(1820), ['k' => 'time_of_day', 'v' => 1820, 's' => '30:20.23'], Kit::duration(3723)][$index % 3]],
            range(0, 5),
        )))]);
        self::assertSame([], $cells['log']['proposal']->mapping->questions);
        self::assertSame(
            [1820, 1820, 3723, 1820, 1820, 3723],
            array_values(array_map(static fn (ParsedImportRow $row): mixed => $row->parsed['seconds'] ?? null, $cells['log']['rows'])),
        );

        // Two plain parts keep today's question: "30:20" may be minutes:seconds or hours:minutes
        $plain = $this->kit->workbook([Kit::sheet('log', self::from(1, array_map(
            static fn (int $index): array => ['Harbour at Dusk number ' . $index, '500', ['30:20', '41:07', '38:02'][$index % 3]],
            range(0, 5),
        )))]);
        self::assertSame(['duration_shape.two_part_text'], array_map(static fn (ImportQuestion $question): string => $question->id(), $plain['log']['proposal']->mapping->questions));
    }

    public function testWithoutTheCataloguesWordTheBrandIsNeverGuessed(): void
    {
        $mapping = $this->kit->analyser->analyse(self::headerless())->mapping;

        self::assertNull($mapping->column(ImportField::Brand));
        self::assertNull($this->analysed(self::headerless(), [])->column(ImportField::Brand));
    }

    public function testTheBrandByItsValuesNeedsAClearMajorityAndNeverBeatsAHeader(): void
    {
        $rows = static fn (array $brands): array => array_map(
            static fn (int $index): array => [$brands[$index % count($brands)], 'Harbour at Dusk number ' . $index, 500 + $index],
            range(0, 11),
        );

        // Under a header nobody knows: three brands we know in every row
        $columns = $this->analysed(Kit::sheet('Shelf', [1 => ['Box says', 'What it shows', 'How many'], ...self::from(2, $rows(['Northwind Puzzles', 'Oakfield', 'paper lantern']))]))->columns;
        self::assertSame([ImportField::Brand, ColumnCertainty::Probable, MappedColumn::BY_BRAND_VALUES], [$columns[0]->field, $columns[0]->certainty, $columns[0]->reason]);

        // Spelled with other spaces and capitals: the catalogue's own rule decides, nothing "similar" is taken
        self::assertNull($this->analysed(Kit::sheet('Shelf', [1 => ['Box says', 'What it shows', 'How many'], ...self::from(2, $rows(['Northwind', 'Oakfeld', 'Lantern Paper']))]))->column(ImportField::Brand));

        // Fewer than 60 % of the distinct values are brands: not the brand
        self::assertNull($this->analysed(Kit::sheet('Shelf', [1 => ['Box says', 'What it shows', 'How many'], ...self::from(2, $rows(['Northwind Puzzles', 'my sister', 'a gift', 'flea market']))]))->column(ImportField::Brand));

        // Fewer than five cells hold a brand: too little to go by
        $few = array_slice($rows(['Northwind Puzzles', 'Oakfield']), 0, 4);
        self::assertNull($this->analysed(Kit::sheet('Shelf', [1 => ['Box says', 'What it shows', 'How many'], ...self::from(2, $few)]))->column(ImportField::Brand));

        // A column the header says is the brand stays the brand - certain - whatever another column holds
        $withHeader = $this->analysed(Kit::sheet('Shelf', [1 => ['Brand', 'Puzzle', 'Bought at'], ...self::from(2, array_map(
            static fn (int $index): array => ['Tidewater ' . $index, 'Harbour at Dusk number ' . $index, ['Northwind Puzzles', 'Oakfield'][$index % 2]],
            range(0, 11),
        ))]));
        self::assertSame([1, ColumnCertainty::Certain], [$withHeader->column(ImportField::Brand)?->column, $withHeader->column(ImportField::Brand)?->certainty]);
        self::assertNotSame(ImportField::Brand, $withHeader->columns[2]->field);

        // A column that has a field is never taken for the brand: the name stays the name
        $taken = ColumnRecogniser::withBrandByValues(
            [new MappedColumn(1, 'Puzzle', ImportField::PuzzleName, ColumnCertainty::Certain, MappedColumn::BY_HEADER)],
            array_map(static fn (int $index): array => Kit::row(['Oakfield']), range(0, 9)),
            static fn (array $texts): array => self::BRANDS,
        );
        self::assertSame(ImportField::PuzzleName, $taken[0]->field);
    }

    public function testTheCatalogueIsAskedOnceForTheWholeSheet(): void
    {
        $asked = [];
        $this->kit->analyser->analyse(self::headerless(), static function (array $texts) use (&$asked): array {
            $asked[] = $texts;

            return array_intersect_key(self::BRANDS, array_flip($texts));
        });

        self::assertCount(1, $asked);
    }

    public function testASheetWeAreSureAboutAKnownExportAndARememberedLayoutAskForNothing(): void
    {
        $sure = $this->analysed(Kit::sheet('Results', [
            1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time'],
            2 => ['Harbour at Dusk', 'Oakfield', 1000, Kit::date('2026-01-18'), Kit::duration(4250)],
            3 => ['Paper Kites', 'Northwind Puzzles', 500, Kit::date('2026-03-02'), Kit::duration(2105)],
            4 => ['The Old Mill', 'Paper Lantern', 1000, Kit::date('2026-04-27'), Kit::duration(3880)],
        ]));

        self::assertNull(ImportCheckFirst::reason($sure));
        self::assertSame($sure, ImportCheckFirst::asked($sure));

        // The same sheet without its header row is a guess...
        $guessed = $this->analysed(self::headerless());
        self::assertNotNull(ImportCheckFirst::reason($guessed));
        // ... a layout the player saved says what its columns are (`remembered`) - then nothing is asked
        $layout = new RememberedLayout(RememberedLayout::WHOLE, '019a0000-0000-7000-8000-0000000000d1', 'My log');
        $remembered = new ImportMapping($guessed->kind, $guessed->kindCertainty, $guessed->rowMeaning, $guessed->regions, 0, array_map(
            static fn (MappedColumn $column): MappedColumn => $column->field === null ? $column : new MappedColumn($column->column, $column->header, $column->field, ColumnCertainty::Certain, MappedColumn::BY_REMEMBERED),
            $guessed->columns,
        ), [], null, $layout);
        self::assertNull(ImportCheckFirst::reason($remembered));
        // ... but a deciding column the layout does not know - the brand, guessed from its values - still holds it
        self::assertSame(ImportCheckFirst::BECAUSE_UNSURE, ImportCheckFirst::reason($guessed->withRemembered($layout)));
        $brandNotInLayout = array_map(
            static fn (MappedColumn $column): MappedColumn => $column->field === ImportField::Brand ? new MappedColumn($column->column, '', ImportField::Brand, ColumnCertainty::Probable, MappedColumn::BY_BRAND_VALUES) : $column,
            $remembered->columns,
        );
        self::assertSame(ImportCheckFirst::BECAUSE_UNSURE, ImportCheckFirst::reason(new ImportMapping($guessed->kind, ColumnCertainty::Certain, $guessed->rowMeaning, $guessed->regions, 0, $brandNotInLayout, [], null, $layout)));
        // ... and a sheet that is not imported is nobody's question
        self::assertNull(ImportCheckFirst::reason($guessed->withKind(DataImportSheetKind::NotPuzzles, ColumnCertainty::Certain)));
    }

    public function testUnderAFoundHeaderRowOnlyAGuessFromValuesOrAnUnknownKindIsCheckedFirst(): void
    {
        // A list of puzzles under headers we know: what it is (owned? a wish list?) is only probable - and that is
        // not a reason to stop the player. It keeps the old "we are not sure - have a look" line and is matched at once
        $owned = $this->analysed(Kit::sheet('Shelf', [
            1 => ['Puzzle', 'Brand', 'Pieces'],
            2 => ['Harbour at Dusk', 'Oakfield', 1000],
            3 => ['Paper Kites', 'Northwind Puzzles', 500],
            4 => ['The Old Mill', 'Paper Lantern', 1000],
        ]));
        self::assertSame(ColumnCertainty::Probable, $owned->kindCertainty);
        self::assertNull(ImportCheckFirst::reason($owned));

        // A deciding column that came from a header word is not a reason either, even when it is only probable
        [$name, $brand, $pieces] = $owned->columns;
        $nameByHeader = new MappedColumn(1, 'Name', ImportField::PuzzleName, ColumnCertainty::Probable, MappedColumn::BY_HEADER_AND_VALUES);
        self::assertNull(ImportCheckFirst::reason(new ImportMapping(DataImportSheetKind::Owned, ColumnCertainty::Probable, $owned->rowMeaning, $owned->regions, 0, [$nameByHeader, $brand, $pieces])));

        // ... but one guessed from its values alone is: the brand found by the catalogue under a header nobody knows
        $guessed = $this->analysed(Kit::sheet('Shelf', [1 => ['Puzzle', 'Box says', 'Pieces'], ...self::from(2, array_map(
            static fn (int $index): array => ['Harbour at Dusk number ' . $index, ['Northwind Puzzles', 'Oakfield'][$index % 2], 500],
            range(0, 7),
        ))]));
        self::assertSame(MappedColumn::BY_BRAND_VALUES, $guessed->column(ImportField::Brand)?->reason);
        self::assertSame(ImportCheckFirst::BECAUSE_UNSURE, ImportCheckFirst::reason($guessed));

        // ... and so is a time or a name no header stands behind
        $timeByValues = new MappedColumn(3, 'Zq', ImportField::Time, ColumnCertainty::Probable, MappedColumn::BY_VALUES);
        self::assertSame(
            ImportCheckFirst::BECAUSE_UNSURE,
            ImportCheckFirst::reason(new ImportMapping(DataImportSheetKind::Results, ColumnCertainty::Probable, DataImportRowMeaning::Result, $owned->regions, 0, [$name, $brand, $timeByValues])),
        );
        // (the time of a sheet that holds no results decides nothing; pieces never do)
        self::assertNull(ImportCheckFirst::reason(new ImportMapping(DataImportSheetKind::Owned, ColumnCertainty::Probable, $owned->rowMeaning, $owned->regions, 0, [$name, $brand, $timeByValues])));
        $piecesByValues = new MappedColumn(3, 'Zq', ImportField::Pieces, ColumnCertainty::Probable, MappedColumn::BY_VALUES);
        self::assertNull(ImportCheckFirst::reason(new ImportMapping(DataImportSheetKind::Owned, ColumnCertainty::Probable, $owned->rowMeaning, $owned->regions, 0, [$name, $brand, $piecesByValues])));

        // The kind could not be told at all (names and nothing else): checked first
        $names = $this->analysed(Kit::sheet('Sheet7', [1 => ['Puzzle', 'Remark'], 2 => ['Harbour at Dusk', 'nice'], 3 => ['Paper Kites', 'hard'], 4 => ['The Old Mill', 'ok']]));
        self::assertSame(ColumnCertainty::Unknown, $names->kindCertainty);
        self::assertSame(ImportCheckFirst::BECAUSE_UNSURE, ImportCheckFirst::reason($names));
    }

    public function testConfirmedTheSheetReadsAsSure(): void
    {
        $guessed = ImportCheckFirst::asked($this->analysed(self::headerless()));
        $confirmed = ImportCheckFirst::confirmed($guessed->withQuestions([]));

        self::assertSame(ColumnCertainty::Certain, $confirmed->kindCertainty);

        foreach ($confirmed->columns as $index => $column) {
            // What each column is stays; that it is, is the player's word now - also "not imported"
            self::assertSame($guessed->columns[$index]->field, $column->field);
            self::assertSame([ColumnCertainty::Certain, MappedColumn::BY_PLAYER, null], [$column->certainty, $column->reason, $column->offered]);
        }

        // The question is gone (it is put only by a proposal - the first one, or "start over"), and the shape reads back
        self::assertFalse(ImportCheckFirst::isAsked($confirmed));
        self::assertEquals($confirmed, ImportMapping::fromArray($confirmed->toArray()));
    }

    public function testEveryRowOfASheetThatAsksForTheCheckIsHeldWithoutPuzzleKeyOrPeople(): void
    {
        $sample = Kit::sheet('Log', [
            1 => ['Puzzle', 'Pieces', 'Time', 'With'],
            2 => ['Harbour at Dusk', 1000, Kit::duration(4250), 'Marta'],
            3 => ['Paper Kites', 500, Kit::duration(2105), null],
            4 => ['Totals', null, null, null],
        ]);
        $mapping = $this->kit->analyser->analyse($sample)->mapping;
        $parse = fn (ImportMapping $mapping, int $row) => PendingQuestionRows::mark(
            $this->kit->parser->parse($row, $sample->rows[$row], $mapping, new ImportSheetOptions(), [], null, []),
            $sample->rows[$row],
            $mapping,
        );

        $read = $parse($mapping, 2);
        self::assertNotNull($read->puzzleKey);
        self::assertNotNull($read->sourceKey);
        self::assertSame(['Marta'], $read->parsed['people'] ?? null);

        $table = $mapping->table();
        self::assertNotNull($table);
        $question = new ImportQuestion(ImportQuestionType::CheckColumns, 1, [ImportCheckFirst::YES]);
        $held = $parse($mapping->withQuestions([$question]), 2);

        // Read - the sheet's page shows what we made of it - but it stands for nothing yet
        self::assertSame(DataImportRowKind::Result, $held->kind);
        self::assertSame('Harbour at Dusk', $held->parsed['name'] ?? null);
        self::assertArrayNotHasKey('people', $held->parsed);
        self::assertNull($held->puzzleKey);
        self::assertNull($held->sourceKey);
        self::assertTrue($held->isWaiting());
        self::assertFalse($held->isUnderstood());
        self::assertSame([['field' => null, 'code' => PendingQuestionRows::COLUMNS_OPEN, 'waiting' => true]], $held->problems);

        // A row that is no data is left out as ever
        self::assertFalse($parse($mapping->withQuestions([$question]), 1)->isData());
    }

    /**
     * @param null|array<string, string> $brands null: the made-up catalogue
     */
    private function analysed(SheetSample $sample, null|array $brands = null): ImportMapping
    {
        $brands ??= self::BRANDS;
        $known = [];

        foreach ($brands as $name => $id) {
            $known[mb_strtolower($name)] = $id;
        }

        return $this->kit->analyser->analyse($sample, static function (array $texts) use ($known): array {
            $resolved = [];

            foreach ($texts as $text) {
                if (isset($known[mb_strtolower($text)])) {
                    $resolved[$text] = $known[mb_strtolower($text)];
                }
            }

            return $resolved;
        })->mapping;
    }

    private static function headerless(): SheetSample
    {
        $brands = ['Northwind Puzzles', 'Paper Lantern', 'Oakfield', 'Northwind Puzzles', 'Tidewater Games'];
        $names = ['Harbour at Dusk', 'Paper Kites over the Bay', 'The Old Mill by the Stream', 'Lanterns in the Rain', 'Market Day in Spring', 'A Quiet Winter Orchard'];
        $artists = ['M. Calloway', 'J. Arden', 'M. Calloway', 'T. Okoro'];
        $rows = [];

        for ($index = 0; $index < 12; $index++) {
            $rows[$index + 1] = [
                sprintf('%02d.%02d.2024', 3 + $index * 2, 1 + $index % 9),
                $brands[$index % count($brands)],
                [1000, 500, 1000, 2000][$index % 4],
                $names[$index % count($names)] . ($index >= count($names) ? ' II' : ''),
                $artists[$index % count($artists)],
                2015 + $index % 8,
                Kit::duration(3000 + $index * 421),
                $index % 3 === 0 ? 'with the cat on the table' : null,
            ];
        }

        return Kit::sheet('Sheet1', $rows);
    }

    /**
     * A CSV log without a header row (every cell a text): date, brand, pieces, puzzle name, time h:mm:ss - the first
     * $sampled rows of $total, made of $puzzles made-up puzzles.
     */
    private static function repeatedLog(int $sampled, int $total, int $puzzles): SheetSample
    {
        $brands = ['Northwind Puzzles', 'Paper Lantern', 'Oakfield'];
        $words = ['Harbour', 'Lanterns', 'Orchard', 'Market Day', 'Kites', 'Old Mill', 'Tide Pools'];
        $places = ['at Dusk', 'in the Rain', 'by the Stream', 'in Spring', 'over the Bay'];
        $rows = [];

        for ($index = 0; $index < $sampled; $index++) {
            $puzzle = $index % $puzzles;
            $seconds = 2400 + ($index * 37) % 9000;
            $rows[$index + 1] = [
                date('Y-m-d', 1546300800 + ($index % 365) * 86400),
                $brands[$puzzle % count($brands)],
                (string) [500, 1500][$puzzle % 2],
                $words[$puzzle % count($words)] . ' ' . $places[intdiv($puzzle, count($words)) % count($places)] . ($puzzle >= 35 ? ' ' . $puzzle : ''),
                sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60),
            ];
        }

        $sheet = Kit::sheet('noheader', $rows);

        return new SheetSample($sheet->title, $sheet->rows, $sheet->hints, $total);
    }

    /**
     * @param list<list<mixed>> $rows
     *
     * @return array<int, list<mixed>> numbered from $first
     */
    private static function from(int $first, array $rows): array
    {
        $numbered = [];

        foreach ($rows as $index => $row) {
            $numbered[$first + $index] = $row;
        }

        return $numbered;
    }
}
