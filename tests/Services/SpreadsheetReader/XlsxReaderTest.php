<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat as SheetNumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\Spreadsheet\CellKind;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\DatePrecision;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadTable;

/**
 * Every workbook here is made up in the test: by PhpSpreadsheet's writer where it can write the shape, part by part
 * (RawXlsx) where it can not.
 */
final class XlsxReaderTest extends TestCase
{
    use SpreadsheetFiles;

    /** 2024-03-05 as a serial number of the 1900 date system */
    private const string MARCH_5_2024 = '45356';

    public function testEveryKindOfCellIsTyped(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->sharedStrings(['Sunset over the bay'])
                ->styles([14, '[h]:mm:ss', 21])
                ->sheet('Kinds', RawXlsx::worksheet([
                    2 => [
                        'A' => RawXlsx::s(0),
                        'B' => RawXlsx::n('1000'),
                        'C' => RawXlsx::n('3.75'),
                        'D' => RawXlsx::n(self::MARCH_5_2024, 1),
                        'E' => RawXlsx::n('0.0389351851851852', 2),
                        'F' => RawXlsx::n('0.75', 3),
                        'G' => RawXlsx::b(true),
                        'H' => RawXlsx::e('#N/A'),
                    ],
                ])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame([2], array_keys($rows));
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_keys($rows[2]->cells));

        $text = self::cell($rows, 2, 1);
        self::assertSame(CellKind::Text, $text->kind);
        self::assertSame('Sunset over the bay', $text->value);
        self::assertSame('Sunset over the bay', $text->asText());
        self::assertSame('Sunset over the bay', $text->shown);

        $whole = self::cell($rows, 2, 2);
        self::assertSame(CellKind::Number, $whole->kind);
        self::assertSame(1000, $whole->value);
        self::assertSame('1000', $whole->shown);

        $decimal = self::cell($rows, 2, 3);
        self::assertSame(3.75, $decimal->asNumber());
        self::assertSame('3.75', $decimal->shown);

        $date = self::cell($rows, 2, 4);
        self::assertSame(CellKind::Date, $date->kind);
        self::assertSame('2024-03-05T00:00:00+00:00', $date->asDate()->format('c'));
        self::assertSame('UTC', $date->asDate()->getTimezone()->getName());
        self::assertSame(DatePrecision::Day, $date->precision);
        self::assertTrue($date->yearShown);
        self::assertNull($date->timeSeconds);
        self::assertSame('2024-03-05', $date->shown);

        $duration = self::cell($rows, 2, 5);
        self::assertSame(CellKind::Duration, $duration->kind);
        self::assertSame(3364, $duration->asSeconds());
        self::assertSame('0:56:04', $duration->shown);

        $time = self::cell($rows, 2, 6);
        self::assertSame(CellKind::TimeOfDay, $time->kind);
        self::assertSame(64800, $time->asSeconds());
        self::assertSame('18:00:00', $time->shown);

        $boolean = self::cell($rows, 2, 7);
        self::assertSame(CellKind::Boolean, $boolean->kind);
        self::assertTrue($boolean->asBoolean());
        self::assertSame('TRUE', $boolean->shown);

        $error = self::cell($rows, 2, 8);
        self::assertSame(CellKind::Error, $error->kind);
        self::assertSame('#N/A', $error->value);

        foreach ($rows[2]->cells as $cell) {
            self::assertFalse($cell->isFormula);
            self::assertNull($cell->hyperlink);
        }
    }

    /**
     * @return iterable<string, array{int|string, DatePrecision, bool, string}> format, precision, year shown?, shown
     */
    public static function dateFormats(): iterable
    {
        yield 'short date (built-in 14)' => [14, DatePrecision::Day, true, '2024-03-05'];
        yield 'm/d' => ['m/d', DatePrecision::Day, false, '--03-05'];
        yield 'mm-dd-yy' => ['mm-dd-yy', DatePrecision::Day, true, '2024-03-05'];
        yield 'm/d/yyyy' => ['m/d/yyyy', DatePrecision::Day, true, '2024-03-05'];
        yield 'd.m.' => ['d.m.', DatePrecision::Day, false, '--03-05'];
        yield 'd-mmm (built-in 16)' => [16, DatePrecision::Day, false, '--03-05'];
        yield 'mmmm yyyy' => ['mmmm\ yyyy', DatePrecision::Month, true, '2024-03'];
        yield 'mmm yy' => ['mmm\ yy', DatePrecision::Month, true, '2024-03'];
        yield 'mmm-yy (built-in 17)' => [17, DatePrecision::Month, true, '2024-03'];
        yield 'month name only' => ['mmmm', DatePrecision::Month, false, '--03'];
        yield 'yyyy' => ['yyyy', DatePrecision::Year, true, '2024'];
    }

    #[DataProvider('dateFormats')]
    public function testADateIsAsExactAsItsFormatShowsIt(int|string $format, DatePrecision $precision, bool $yearShown, string $shown): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())->styles([$format])->sheet('Dates', RawXlsx::worksheet([1 => ['A' => RawXlsx::n(self::MARCH_5_2024, 1)]])),
        ));

        $cell = self::cell($this->rowsOf($workbook), 1, 1);

        self::assertSame(CellKind::Date, $cell->kind);
        // The day the cell holds is always handed over - the precision says how much of it the player ever saw
        self::assertSame('2024-03-05', $cell->asDate()->format('Y-m-d'));
        self::assertSame($precision, $cell->precision);
        self::assertSame($yearShown, $cell->yearShown);
        self::assertSame($shown, $cell->shown);
    }

    public function testADurationOverADayATimeOfDayAndADateWithATimeAreToldApart(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->styles(['[h]:mm:ss', 'h:mm:ss', 'h:mm AM/PM', 'm/d/yyyy h:mm', 'mm:ss', '[mm]:ss', 46, 20])
                ->sheet('Times', RawXlsx::worksheet([
                    1 => [
                        // 25 h 30 min
                        'A' => RawXlsx::n('1.0625', 1),
                        // The same number shown as a time of day wraps at midnight: the player saw 1:30:00
                        'B' => RawXlsx::n('1.0625', 2),
                        'C' => RawXlsx::n('0.5625', 3),
                        'D' => RawXlsx::n('45356.5625', 4),
                        'E' => RawXlsx::n('0.0087268518518519', 5),
                        'F' => RawXlsx::n('0.0520833333333333', 6),
                        'G' => RawXlsx::n('2.5', 7),
                        'H' => RawXlsx::n('0.25', 8),
                        // A time of day that the format calls a date and a time: there is no day 0
                        'I' => RawXlsx::n('0.5625', 4),
                        'J' => RawXlsx::n('-0.25', 1),
                    ],
                ])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame([CellKind::Duration, 91800, '25:30:00'], self::kindValueShown(self::cell($rows, 1, 1)));
        self::assertSame([CellKind::TimeOfDay, 5400, '1:30:00'], self::kindValueShown(self::cell($rows, 1, 2)));
        self::assertSame([CellKind::TimeOfDay, 48600, '13:30:00'], self::kindValueShown(self::cell($rows, 1, 3)));

        $dateTime = self::cell($rows, 1, 4);
        self::assertSame(CellKind::Date, $dateTime->kind);
        self::assertSame('2024-03-05 00:00:00', $dateTime->asDate()->format('Y-m-d H:i:s'));
        self::assertSame(48600, $dateTime->timeSeconds);
        self::assertSame('2024-03-05 13:30:00', $dateTime->shown);

        self::assertSame([CellKind::TimeOfDay, 754, '0:12:34'], self::kindValueShown(self::cell($rows, 1, 5)));
        self::assertSame([CellKind::Duration, 4500, '1:15:00'], self::kindValueShown(self::cell($rows, 1, 6)));
        self::assertSame([CellKind::Duration, 216000, '60:00:00'], self::kindValueShown(self::cell($rows, 1, 7)));
        self::assertSame([CellKind::TimeOfDay, 21600, '6:00:00'], self::kindValueShown(self::cell($rows, 1, 8)));
        self::assertSame([CellKind::TimeOfDay, 48600, '13:30:00'], self::kindValueShown(self::cell($rows, 1, 9)));
        self::assertSame([CellKind::Duration, -21600, '-6:00:00'], self::kindValueShown(self::cell($rows, 1, 10)));
    }

    public function testATimeTypedAsTextStaysText(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->sharedStrings(['0:56:04', '1:13:??', '  padded  ', '=not a formula', "'=kept as typed"])
                ->styles(['[h]:mm:ss'])
                ->sheet('Texts', RawXlsx::worksheet([
                    1 => [
                        // A text cell stays text even when its style is a duration
                        'A' => RawXlsx::s(0, 1),
                        'B' => RawXlsx::s(1),
                        'C' => RawXlsx::s(2),
                        'D' => RawXlsx::s(3),
                        'E' => RawXlsx::s(4),
                        'F' => RawXlsx::inline('0:56:04'),
                    ],
                ])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame([CellKind::Text, '0:56:04', '0:56:04'], self::kindValueShown(self::cell($rows, 1, 1)));
        self::assertSame('1:13:??', self::cell($rows, 1, 2)->value);
        // Nothing is trimmed
        self::assertSame('  padded  ', self::cell($rows, 1, 3)->value);
        // A text that starts with "=" is a text, not a formula
        self::assertSame([CellKind::Text, '=not a formula', '=not a formula'], self::kindValueShown(self::cell($rows, 1, 4)));
        self::assertFalse(self::cell($rows, 1, 4)->isFormula);
        // The apostrophe our own export puts in front is left alone here (SpreadsheetSafeValue::withoutFormulaGuard)
        self::assertSame("'=kept as typed", self::cell($rows, 1, 5)->value);
        self::assertSame([CellKind::Text, '0:56:04', '0:56:04'], self::kindValueShown(self::cell($rows, 1, 6)));
    }

    public function testSharedInlineAndRichTextsAreRead(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->sharedStrings([
                    'Plain',
                    '<si><r><rPr><b/></rPr><t xml:space="preserve">Bold </t></r><r><t>and plain</t></r></si>',
                    'Žluťoučký kůň & <tags> "quoted"',
                    "Two\nlines",
                    '',
                ])
                ->sheet('Texts', RawXlsx::worksheet([
                    1 => ['A' => RawXlsx::s(0), 'B' => RawXlsx::s(1), 'C' => RawXlsx::s(2), 'D' => RawXlsx::s(3), 'E' => RawXlsx::s(4), 'F' => RawXlsx::inline('Inline & text')],
                ])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame('Plain', self::cell($rows, 1, 1)->value);
        self::assertSame('Bold and plain', self::cell($rows, 1, 2)->value);
        self::assertSame('Žluťoučký kůň & <tags> "quoted"', self::cell($rows, 1, 3)->value);
        self::assertSame("Two\nlines", self::cell($rows, 1, 4)->value);
        // An empty text is no value
        self::assertNull($rows[1]->cell(5));
        self::assertSame('Inline & text', self::cell($rows, 1, 6)->value);
    }

    public function testWhatASpreadsheetEscapesInATextComesBack(): void
    {
        $sheetData = '<sheetData><row r="1">'
            . '<c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c>'
            // Rich text typed in place, with the reading aid of a Japanese title - which is not the title
            . '<c r="E1" t="inlineStr"><is><r><t xml:space="preserve">東京 </t></r><r><t>タワー</t></r><rPh sb="0" eb="2"><t>トウキョウ</t></rPh></is></c>'
            . '<c r="F1" t="str"><f>" padded "</f><v xml:space="preserve"> padded </v></c>'
            . '<c r="G1" t="inlineStr"><is><t>line_x000D_break</t></is></c>'
            . '<c r="H1" t="inlineStr"><is><t><![CDATA[<b>not markup</b> & more]]></t></is></c>'
            . '</row></sheetData>';

        $rows = $this->rowsOf($this->open($this->saved(
            (new RawXlsx())
                ->sharedStrings(["first line_x000D_\nsecond line", 'a literal _x005F_x000D_ sequence', 'bell_x0007_ and null_x0000_ gone', '<si><t>東京</t><rPh sb="0" eb="2"><t>トウキョウ</t></rPh></si>'])
                ->sheet('Escapes', RawXlsx::worksheetAround($sheetData)),
        )));

        self::assertSame("first line\r\nsecond line", self::cell($rows, 1, 1)->value);
        self::assertSame('a literal _x000D_ sequence', self::cell($rows, 1, 2)->value);
        self::assertSame('bell and null gone', self::cell($rows, 1, 3)->value);
        self::assertSame('東京', self::cell($rows, 1, 4)->value);
        self::assertSame('東京 タワー', self::cell($rows, 1, 5)->value);
        self::assertSame(' padded ', self::cell($rows, 1, 6)->value);
        self::assertTrue(self::cell($rows, 1, 6)->isFormula);
        self::assertSame("line\rbreak", self::cell($rows, 1, 7)->value);
        self::assertSame('<b>not markup</b> & more', self::cell($rows, 1, 8)->value);
    }

    public function testAFormulaGivesTheValueTheSpreadsheetSavedAndIsNeverCalculated(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->styles([14, '[h]:mm:ss', 'mmmm\ yyyy'])
                ->sheet('Formulas', RawXlsx::worksheet([
                    1 => [
                        'A' => RawXlsx::f('SUM(B1:B9)', '42'),
                        'B' => RawXlsx::f('TODAY()', self::MARCH_5_2024, null, 1),
                        'C' => RawXlsx::f('B2-A2', '0.0389351851851852', null, 2),
                        'D' => RawXlsx::f('1/0', '#DIV/0!', 'e'),
                        // Never opened in a spreadsheet after it was written: no saved value
                        'E' => RawXlsx::f('SUM(A1:A9)', null),
                        'F' => RawXlsx::f('A1&" pieces"', '42 pieces', 'str'),
                        'G' => RawXlsx::f('A1>1', '1', 'b'),
                        'H' => RawXlsx::f('EOMONTH(B1,0)', self::MARCH_5_2024, null, 3),
                        'I' => RawXlsx::f('""', '', 'str'),
                        // A formula that would be dangerous to run: it is not run
                        'J' => RawXlsx::f('WEBSERVICE("http://attacker.test/")', '7'),
                    ],
                ])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame([1, 2, 3, 4, 6, 7, 8, 10], array_keys($rows[1]->cells));

        foreach ($rows[1]->cells as $cell) {
            self::assertTrue($cell->isFormula);
        }

        self::assertSame([CellKind::Number, 42, '42'], self::kindValueShown(self::cell($rows, 1, 1)));
        self::assertSame(CellKind::Date, self::cell($rows, 1, 2)->kind);
        self::assertSame('2024-03-05', self::cell($rows, 1, 2)->shown);
        self::assertSame([CellKind::Duration, 3364, '0:56:04'], self::kindValueShown(self::cell($rows, 1, 3)));
        self::assertSame([CellKind::Error, '#DIV/0!', '#DIV/0!'], self::kindValueShown(self::cell($rows, 1, 4)));
        self::assertSame([CellKind::Text, '42 pieces', '42 pieces'], self::kindValueShown(self::cell($rows, 1, 6)));
        self::assertSame([CellKind::Boolean, true, 'TRUE'], self::kindValueShown(self::cell($rows, 1, 7)));
        self::assertSame(DatePrecision::Month, self::cell($rows, 1, 8)->precision);
        self::assertSame([CellKind::Number, 7, '7'], self::kindValueShown(self::cell($rows, 1, 10)));
    }

    public function testASheetOfNothingButFormulasIsReadFromTheirSavedValues(): void
    {
        $cells = [];

        for ($row = 1; $row <= 40; $row++) {
            $cells[$row] = [
                'A' => RawXlsx::f(sprintf('IMPORTRANGE("x","A%d")', $row), sprintf('Puzzle %d', $row), 'str'),
                'B' => RawXlsx::f(sprintf('IMPORTRANGE("x","B%d")', $row), (string) (500 + $row)),
            ];
        }

        $rows = $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Imported', RawXlsx::worksheet($cells)))));

        self::assertCount(40, $rows);
        self::assertSame('Puzzle 40', self::cell($rows, 40, 1)->value);
        self::assertSame(540, self::cell($rows, 40, 2)->value);
        self::assertTrue(self::cell($rows, 40, 2)->isFormula);
    }

    public function testATableUnderADashboardOfFormulasSaysWhereTheHeaderIs(): void
    {
        $cells = [
            1 => ['A' => RawXlsx::inline('Solved'), 'B' => RawXlsx::f('COUNTA(A7:A120)', '3')],
            2 => ['A' => RawXlsx::inline('Fastest'), 'B' => RawXlsx::f('MIN(C7:C120)', '0.03', null, 1)],
            3 => ['A' => RawXlsx::inline('Average'), 'B' => RawXlsx::f('AVERAGE(C7:C120)', '0.04', null, 1)],
            6 => ['A' => RawXlsx::inline('Puzzle'), 'B' => RawXlsx::inline('Pieces'), 'C' => RawXlsx::inline('Time'), 'D' => RawXlsx::inline('On MSP?')],
            7 => ['A' => RawXlsx::inline('Made-up Meadow'), 'B' => RawXlsx::n('500'), 'C' => RawXlsx::n('0.03', 1), 'D' => RawXlsx::b(true)],
            8 => ['A' => RawXlsx::inline('Invented Harbour'), 'B' => RawXlsx::n('500'), 'C' => RawXlsx::n('0.05', 1), 'D' => RawXlsx::b(false)],
        ];

        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->styles(['[h]:mm:ss'])
                ->sheet(
                    'Results',
                    RawXlsx::worksheet($cells, '<dimension ref="A1:D120"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'),
                    tables: [RawXlsx::table('Results', 'A6:D120')],
                ),
        ));

        // Known before any row is read
        $sheet = $workbook->sheets()[0];

        self::assertCount(1, $sheet->tables);
        self::assertSame('Results', $sheet->tables[0]->name);
        self::assertSame('A6:D120', $sheet->tables[0]->range->toA1());
        self::assertSame(6, $sheet->tables[0]->headerRow);
        self::assertSame(7, $sheet->tables[0]->firstDataRow());
        self::assertSame(120, $sheet->tables[0]->lastDataRow());
        self::assertSame(7, $sheet->frozenPane?->firstUnfrozenRow);
        self::assertSame(1, $sheet->frozenPane->firstUnfrozenColumn);
        self::assertSame('A1:D120', $sheet->usedRange?->toA1());

        $rows = $this->rowsOf($workbook);

        self::assertSame([1, 2, 3, 6, 7, 8], array_keys($rows));
        self::assertSame('Puzzle', self::cell($rows, 6, 1)->value);
        self::assertTrue(self::cell($rows, 1, 2)->isFormula);
    }

    public function testSeveralTablesSideBySideAreSeveralRegions(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())->sheet(
                'Drills',
                RawXlsx::worksheet([1 => ['A' => RawXlsx::inline('Day'), 'E' => RawXlsx::inline('Day')]]),
                tables: [
                    RawXlsx::table('Right', 'E1:G30'),
                    RawXlsx::table('Left', 'A1:C12', totalsRows: 1),
                    RawXlsx::table('Below', 'A20:C25', headerRows: 0),
                ],
            ),
        ));

        $tables = $workbook->sheets()[0]->tables;

        // Top to bottom, left to right - whatever order the file lists them in
        self::assertSame(['Left', 'Right', 'Below'], array_map(static fn (ReadTable $table): string => $table->name, $tables));
        self::assertSame('A1:C12', $tables[0]->range->toA1());
        self::assertSame(1, $tables[0]->headerRow);
        self::assertSame(1, $tables[0]->totalsRows);
        self::assertSame(11, $tables[0]->lastDataRow());
        self::assertSame('E1:G30', $tables[1]->range->toA1());
        self::assertSame(3, $tables[1]->range->columns());
        // A table without a header row
        self::assertNull($tables[2]->headerRow);
        self::assertSame(20, $tables[2]->firstDataRow());
    }

    public function testFrozenColumnsAndRowsAreBothKnown(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->sheet('Both', RawXlsx::worksheet([], '<sheetViews><sheetView workbookViewId="0"><pane xSplit="2" ySplit="1" topLeftCell="C2" state="frozen"/></sheetView></sheetViews>'))
                ->sheet('Split only', RawXlsx::worksheet([], '<sheetViews><sheetView workbookViewId="0"><pane xSplit="2000" ySplit="1000" topLeftCell="C2"/></sheetView></sheetViews>'))
                ->sheet('None', RawXlsx::worksheet([])),
        ));

        [$both, $split, $none] = $workbook->sheets();

        self::assertSame(2, $both->frozenPane?->firstUnfrozenRow);
        self::assertSame(3, $both->frozenPane->firstUnfrozenColumn);
        // A pane that is split but not frozen says nothing about where the data starts
        self::assertNull($split->frozenPane);
        self::assertNull($none->frozenPane);
        self::assertNull($none->usedRange);
        self::assertSame([], $none->tables);
    }

    public function testSheetsAreListedInTabOrderWithHiddenOnesMarked(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->sheet('2024', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]]))
                ->sheet('Lists', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('2')]]), 'hidden')
                ->sheet('Internal', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('3')]]), 'veryHidden')
                ->sheet('To do & "more"', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('4')]])),
        ));

        $sheets = $workbook->sheets();

        self::assertSame(['2024', 'Lists', 'Internal', 'To do & "more"'], array_column($sheets, 'title'));
        self::assertSame([1, 2, 3, 4], array_column($sheets, 'position'));
        self::assertSame([false, true, true, false], array_column($sheets, 'hidden'));

        // A hidden sheet is read like any other, and each sheet gives its own rows
        self::assertSame(2, self::cell($this->rowsOf($workbook, 2), 1, 1)->value);
        self::assertSame(4, self::cell($this->rowsOf($workbook, 4), 1, 1)->value);
        self::assertSame(1, self::cell($this->rowsOf($workbook, 1), 1, 1)->value);
    }

    public function testAChartSheetIsNotASheetOfCells(): void
    {
        $path = $this->saved(
            (new RawXlsx())
                ->sheet('First', RawXlsx::worksheet([1 => ['A' => RawXlsx::inline('first')]]))
                ->sheet('Second', RawXlsx::worksheet([1 => ['A' => RawXlsx::inline('second')]]))
                ->part('xl/chartsheets/sheet1.xml', '<?xml version="1.0"?><chartsheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>')
                ->part('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
                    . '<sheet name="First" sheetId="1" r:id="rId1"/><sheet name="Chart" sheetId="3" r:id="rIdChart"/><sheet name="Second" sheetId="2" r:id="rId2"/></sheets></workbook>')
                ->part('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                    . '<Relationship Id="rIdChart" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chartsheet" Target="chartsheets/sheet1.xml"/>'
                    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/sheet2.xml"/>'
                    . '<Relationship Id="rIdStrings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                    . '</Relationships>'),
        );

        $workbook = $this->open($path);

        self::assertSame(['First', 'Second'], array_column($workbook->sheets(), 'title'));
        self::assertSame('second', self::cell($this->rowsOf($workbook, 2), 1, 1)->value);
        self::assertSame('first', self::cell($this->rowsOf($workbook, 1), 1, 1)->value);
    }

    public function testRowsOfOnlyUntickedCheckboxesAreHandedOverAsBooleans(): void
    {
        $cells = [1 => ['A' => RawXlsx::inline('Puzzle'), 'B' => RawXlsx::inline('No box?'), 'C' => RawXlsx::inline('On MSP?')]];

        for ($row = 2; $row <= 601; $row++) {
            $cells[$row] = ['B' => RawXlsx::b(false), 'C' => RawXlsx::b(false)];
        }

        $rows = $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Stretched', RawXlsx::worksheet($cells)))));

        // 600 rows that hold nothing but FALSE: the reader hands them over, the layer above calls them empty
        self::assertCount(601, $rows);

        foreach (array_slice($rows, 1, null, true) as $row) {
            self::assertSame([2, 3], array_keys($row->cells));
            self::assertSame(CellKind::Boolean, $row->cells[2]->kind);
            self::assertFalse($row->cells[2]->value);
            self::assertSame('FALSE', $row->cells[3]->shown);
        }
    }

    public function testEmptyRowsAreSkippedAndFarAwayCellsKeepTheirPlace(): void
    {
        $sheetData = '<sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>Header</t></is></c></row>'
            // Rows that exist but hold nothing: empty, styled only, a formula without a saved value
            . '<row r="2"/>'
            . '<row r="3" s="1" customFormat="1"><c r="A3" s="1"/><c r="B3" s="1"></c></row>'
            . '<row r="4"><c r="A4"><f>A1</f></c><c r="B4"><v></v></c></row>'
            . '<row r="7"><c r="C7"><v>7</v></c></row>'
            // Trailing formatted rows
            . '<row r="8" s="1"/><row r="9" s="1"/>'
            // A stray value far away
            . '<row r="50000"><c r="AX50000" t="inlineStr"><is><t>stray</t></is></c></row>'
            . '<row r="1048576"><c r="A1048576"><v>1</v></c></row>'
            . '</sheetData>';

        $workbook = $this->open($this->saved(
            (new RawXlsx())->styles([14])->sheet('Gaps', RawXlsx::worksheetAround($sheetData, '<dimension ref="A1:AX1048576"/>')),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame([1, 7, 50000, 1048576], array_keys($rows));
        self::assertSame([3], array_keys($rows[7]->cells));
        self::assertSame(3, $rows[7]->lastColumn());
        self::assertSame('stray', self::cell($rows, 50000, 50)->value);
        self::assertSame(1, self::cell($rows, 1048576, 1)->value);
        self::assertSame('A1:AX1048576', $workbook->sheets()[0]->usedRange?->toA1());
    }

    public function testRowsAndCellsWithoutReferencesAreCounted(): void
    {
        // Some writers leave the "r" attributes out: rows and cells then follow one another
        $sheetData = '<sheetData>'
            . '<row><c t="inlineStr"><is><t>a</t></is></c><c><v>1</v></c></row>'
            . '<row><c t="inlineStr"><is><t>b</t></is></c><c><v>2</v></c></row>'
            . '<row r="5"><c r="C5"><v>3</v></c><c><v>4</v></c></row>'
            . '<row><c><v>5</v></c></row>'
            . '</sheetData>';

        $rows = $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Loose', RawXlsx::worksheetAround($sheetData)))));

        self::assertSame([1, 2, 5, 6], array_keys($rows));
        self::assertSame('b', self::cell($rows, 2, 1)->value);
        self::assertSame(2, self::cell($rows, 2, 2)->value);
        self::assertSame([3, 4], array_keys($rows[5]->cells));
        self::assertSame(5, self::cell($rows, 6, 1)->value);
    }

    public function testARowNumberThatGoesBackwardsOrRepeatsIsPassedOver(): void
    {
        $sheetData = '<sheetData>'
            . '<row r="5"><c r="A5"><v>5</v></c></row>'
            . '<row r="3"><c r="A3"><v>3</v></c></row>'
            . '<row r="5"><c r="A5"><v>55</v></c></row>'
            . '<row r="9"><c r="A9"><v>9</v></c></row>'
            . '</sheetData>';

        $rows = $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Odd', RawXlsx::worksheetAround($sheetData)))));

        self::assertSame([5, 9], array_keys($rows));
        self::assertSame(5, self::cell($rows, 5, 1)->value);
        self::assertSame(9, self::cell($rows, 9, 1)->value);
    }

    public function testRowsHiddenByAFilterAreReadLikeAnyOther(): void
    {
        $sheetData = '<sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>Brand</t></is></c></row>'
            . '<row r="2" hidden="1"><c r="A2" t="inlineStr"><is><t>Filtered out</t></is></c></row>'
            . '<row r="3"><c r="A3" t="inlineStr"><is><t>Shown</t></is></c></row>'
            . '</sheetData>';

        $rows = $this->rowsOf($this->open($this->saved(
            (new RawXlsx())->sheet('Filtered', RawXlsx::worksheetAround($sheetData, '', '<autoFilter ref="A1:A3"><filterColumn colId="0"><filters><filter val="Shown"/></filters></filterColumn></autoFilter>')),
        )));

        self::assertSame([1, 2, 3], array_keys($rows));
        self::assertSame('Filtered out', self::cell($rows, 2, 1)->value);
    }

    public function testMergedRangesAreHandedOutAndTheValueSitsInTheFirstCell(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->sheet('Merged', RawXlsx::worksheet(
                    [1 => ['A' => RawXlsx::inline('2024 results')], 3 => ['A' => RawXlsx::inline('Team Made-up'), 'B' => RawXlsx::n('1')]],
                    '',
                    '<mergeCells count="3"><mergeCell ref="A1:D1"/><mergeCell ref="A3:A6"/><mergeCell ref="not a range"/></mergeCells>',
                ))
                ->sheet('Plain', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]])),
        ));

        [$merged, $plain] = $workbook->sheets();

        self::assertSame(['A1:D1', 'A3:A6'], array_map(static fn (CellRange $range): string => $range->toA1(), $workbook->mergedRanges($merged)));
        self::assertSame([], $workbook->mergedRanges($plain));

        $rows = $this->rowsOf($workbook);

        self::assertSame([1], array_keys($rows[1]->cells));
        self::assertSame('2024 results', self::cell($rows, 1, 1)->value);
    }

    public function testALinkIsHandedOverWhenItIsNotTheCellsOwnText(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->sheet(
                    'Links',
                    RawXlsx::worksheet([
                        1 => ['A' => RawXlsx::inline('Picture'), 'B' => RawXlsx::inline('https://example.test/typed'), 'C' => RawXlsx::n('12')],
                        2 => ['A' => RawXlsx::inline('No link')],
                    ]),
                    links: ['A1' => 'https://example.test/picture.jpg?id=1&size=2', 'B1' => 'https://example.test/typed', 'C1:D1' => 'https://example.test/number', 'F9' => 'https://example.test/empty-cell'],
                )
                ->sheet('No links', RawXlsx::worksheet([1 => ['A' => RawXlsx::inline('Plain')]])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame('Picture', self::cell($rows, 1, 1)->value);
        self::assertSame('https://example.test/picture.jpg?id=1&size=2', self::cell($rows, 1, 1)->hyperlink);
        // The cell shows the address itself: nothing to add
        self::assertNull(self::cell($rows, 1, 2)->hyperlink);
        self::assertSame(12, self::cell($rows, 1, 3)->value);
        self::assertSame('https://example.test/number', self::cell($rows, 1, 3)->hyperlink);
        self::assertNull(self::cell($rows, 2, 1)->hyperlink);
        self::assertNull(self::cell($this->rowsOf($workbook, 2), 1, 1)->hyperlink);
    }

    public function testTheDateSystemOf1904IsHonoured(): void
    {
        $sheet = RawXlsx::worksheet([1 => ['A' => RawXlsx::n('43894', 1), 'B' => RawXlsx::n('0', 1), 'C' => RawXlsx::n('0.5', 2)]]);

        $rows1904 = $this->rowsOf($this->open($this->saved((new RawXlsx())->date1904()->styles([14, 21])->sheet('Mac', $sheet))));
        $rows1900 = $this->rowsOf($this->open($this->saved((new RawXlsx())->styles([14, 21])->sheet('Windows', $sheet))));

        // The same number is 1,462 days later in a workbook that counts from 1904
        self::assertSame('2024-03-05', self::cell($rows1904, 1, 1)->asDate()->format('Y-m-d'));
        self::assertSame('2020-03-04', self::cell($rows1900, 1, 1)->asDate()->format('Y-m-d'));
        self::assertSame('1904-01-01', self::cell($rows1904, 1, 2)->asDate()->format('Y-m-d'));
        self::assertSame(43200, self::cell($rows1904, 1, 3)->asSeconds());
    }

    public function testADateDoesNotDependOnTheServersTimeZone(): void
    {
        $path = $this->saved((new RawXlsx())->styles([14, 'm/d/yyyy h:mm'])->sheet('Zones', RawXlsx::worksheet([
            1 => ['A' => RawXlsx::n(self::MARCH_5_2024, 1), 'B' => RawXlsx::n('45382.104166666664', 2)],
        ])));
        $zone = date_default_timezone_get();

        try {
            foreach (['Pacific/Auckland', 'America/Los_Angeles', 'Europe/Prague'] as $serverZone) {
                date_default_timezone_set($serverZone);
                $rows = $this->rowsOf($this->open($path));

                self::assertSame('2024-03-05T00:00:00+00:00', self::cell($rows, 1, 1)->asDate()->format('c'));
                // 2:30 in the night Europe skips that hour: a spreadsheet knows no time zones, the cell says 2:30
                self::assertSame('2024-03-31 02:30:00', self::cell($rows, 1, 2)->shown);
            }
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testDatesAtTheEdgesOfTheCalendar(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->styles([14])
                ->sheet('Edges', RawXlsx::worksheet([
                    1 => [
                        'A' => RawXlsx::n('1', 1),
                        'B' => RawXlsx::n('59', 1),
                        'C' => RawXlsx::n('61', 1),
                        'D' => RawXlsx::n('2958465', 1),
                        // What a spreadsheet shows as #####: no date at all
                        'E' => RawXlsx::n('-5', 1),
                        'F' => RawXlsx::n('2958466', 1),
                        // A date typed with a wrong year is still a date - the layer above calls it a problem
                        'G' => RawXlsx::n('46818', 1),
                        // Rounding up to midnight moves the day
                        'H' => RawXlsx::n('45355.999999999', 1),
                    ],
                ])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame('1900-01-01', self::cell($rows, 1, 1)->asDate()->format('Y-m-d'));
        self::assertSame('1900-02-28', self::cell($rows, 1, 2)->asDate()->format('Y-m-d'));
        self::assertSame('1900-03-01', self::cell($rows, 1, 3)->asDate()->format('Y-m-d'));
        self::assertSame('9999-12-31', self::cell($rows, 1, 4)->asDate()->format('Y-m-d'));
        self::assertSame([CellKind::Number, -5, '-5'], self::kindValueShown(self::cell($rows, 1, 5)));
        self::assertSame(CellKind::Number, self::cell($rows, 1, 6)->kind);
        self::assertSame('2028-03-06', self::cell($rows, 1, 7)->asDate()->format('Y-m-d'));
        self::assertSame('2024-03-05', self::cell($rows, 1, 8)->asDate()->format('Y-m-d'));
    }

    public function testNumbersAreShownWithoutFloatNoise(): void
    {
        $workbook = $this->open($this->saved(
            (new RawXlsx())
                ->styles(['0.00%', '#,##0.00', '@'])
                ->sheet('Numbers', RawXlsx::worksheet([
                    1 => [
                        'A' => RawXlsx::n('0.30000000000000004'),
                        'B' => RawXlsx::n('4005556123457'),
                        'C' => RawXlsx::n('4.00556E+12'),
                        'D' => RawXlsx::n('0.125', 1),
                        'E' => RawXlsx::n('1234.5', 2),
                        'F' => RawXlsx::n('12.0'),
                        'G' => RawXlsx::n('-0.5'),
                        'H' => RawXlsx::n('17', 3),
                        'I' => RawXlsx::n('1E+20'),
                    ],
                ])),
        ));

        $rows = $this->rowsOf($workbook);

        self::assertSame('0.3', self::cell($rows, 1, 1)->shown);
        self::assertSame(0.30000000000000004, self::cell($rows, 1, 1)->value);
        // A barcode stays a whole number
        self::assertSame([CellKind::Number, 4005556123457, '4005556123457'], self::kindValueShown(self::cell($rows, 1, 2)));
        // A barcode a spreadsheet damaged: the value is what was saved
        self::assertSame([CellKind::Number, 4005560000000, '4005560000000'], self::kindValueShown(self::cell($rows, 1, 3)));
        self::assertSame([CellKind::Number, 0.125, '12.5%'], self::kindValueShown(self::cell($rows, 1, 4)));
        self::assertSame([CellKind::Number, 1234.5, '1234.5'], self::kindValueShown(self::cell($rows, 1, 5)));
        self::assertSame([CellKind::Number, 12, '12'], self::kindValueShown(self::cell($rows, 1, 6)));
        self::assertSame('-0.5', self::cell($rows, 1, 7)->shown);
        self::assertSame([CellKind::Number, 17, '17'], self::kindValueShown(self::cell($rows, 1, 8)));
        self::assertSame(1.0E+20, self::cell($rows, 1, 9)->value);
    }

    public function testADateWrittenOutInIsoFormIsADate(): void
    {
        $sheetData = '<sheetData><row r="1">'
            . '<c r="A1" t="d"><v>2024-03-05</v></c>'
            . '<c r="B1" t="d"><v>2024-03-05T13:30:00Z</v></c>'
            . '<c r="C1" t="d" s="1"><v>2024-03-05T13:30:00</v></c>'
            . '<c r="D1" t="d"><v>not a date</v></c>'
            . '<c r="E1" t="d"><v>2024-02-31</v></c>'
            . '</row></sheetData>';

        $rows = $this->rowsOf($this->open($this->saved((new RawXlsx())->styles(['mmmm\ yyyy'])->sheet('Strict', RawXlsx::worksheetAround($sheetData)))));

        self::assertSame('2024-03-05', self::cell($rows, 1, 1)->shown);
        self::assertSame(DatePrecision::Day, self::cell($rows, 1, 1)->precision);
        self::assertSame('2024-03-05 13:30:00', self::cell($rows, 1, 2)->shown);
        self::assertSame(48600, self::cell($rows, 1, 2)->timeSeconds);
        self::assertSame('2024-03', self::cell($rows, 1, 3)->shown);
        self::assertNull(self::cell($rows, 1, 3)->timeSeconds);
        self::assertSame([CellKind::Text, 'not a date', 'not a date'], self::kindValueShown(self::cell($rows, 1, 4)));
        self::assertSame(CellKind::Text, self::cell($rows, 1, 5)->kind);
    }

    public function testASheetCanBeStreamedAgainAndOnlyByItsOwnWorkbook(): void
    {
        $path = $this->saved((new RawXlsx())->sheet('Twice', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')], 2 => ['A' => RawXlsx::n('2')]])));
        $workbook = $this->open($path);
        $other = $this->open($path);
        $sheet = $workbook->sheets()[0];

        // Abandoned half-way
        foreach ($workbook->rows($sheet) as $row) {
            self::assertSame(1, $row->number);

            break;
        }

        self::assertSame([1, 2], array_keys($this->rowsOf($workbook)));
        self::assertSame([1, 2], array_keys($this->rowsOf($workbook)));

        try {
            $other->rows($sheet);
            self::fail('A sheet of another workbook was accepted.');
        } catch (\InvalidArgumentException) {
        }

        $workbook->close();
        $workbook->close();

        $this->expectException(\InvalidArgumentException::class);
        $workbook->rows($sheet);
    }

    public function testAWorkbookWrittenByASpreadsheetLibraryIsReadAsItWasWritten(): void
    {
        $spreadsheet = new Spreadsheet();
        $results = $spreadsheet->getActiveSheet();
        $results->setTitle('Results 2024');

        // A dashboard above the table
        $results->setCellValue('A1', 'Solved');
        $results->setCellValue('B1', '=COUNTA(A4:A6)');
        $results->mergeCells('C1:E1');

        $results->fromArray(['Puzzle', 'Pieces', 'Finished', 'Time', 'Month', 'No box?', 'Share'], null, 'A3');
        $data = [
            ['Made-up Meadow', 500, 45356, 0.0389351851851852, 45356, false, 0.25],
            ['Invented Harbour', 1000, 45357, 1.0625, 45357, true, 0.5],
            ['Imaginary Alps', 300, 45358, 0.5, 45358, false, 1],
        ];

        foreach ($data as $index => [$puzzle, $pieces, $finished, $time, $month, $noBox, $share]) {
            $row = 4 + $index;
            $results->setCellValueExplicit('A' . $row, $puzzle, DataType::TYPE_STRING);
            $results->setCellValue('B' . $row, $pieces);
            $results->setCellValue('C' . $row, $finished);
            $results->setCellValue('D' . $row, $time);
            $results->setCellValue('E' . $row, $month);
            $results->setCellValue('F' . $row, $noBox);
            $results->setCellValue('G' . $row, $share);
        }

        $results->getStyle('C4:C6')->getNumberFormat()->setFormatCode('m/d');
        $results->getStyle('D4:D6')->getNumberFormat()->setFormatCode('[h]:mm:ss');
        $results->getStyle('E4:E6')->getNumberFormat()->setFormatCode('mmmm yyyy');
        $results->getStyle('G4:G6')->getNumberFormat()->setFormatCode(SheetNumberFormat::FORMAT_PERCENTAGE_00);
        $results->setCellValue('H4', '=B4/(D4*1440)');
        $results->freezePane('A4');
        $results->addTable(new Table('A3:G6', 'ResultsTable'));
        $results->getCell('A4')->getHyperlink()->setUrl('https://example.test/meadow');

        $lists = new Worksheet($spreadsheet, 'Lists');
        $spreadsheet->addSheet($lists);
        $lists->setCellValue('A1', 'Brand');
        $lists->setCellValue('A2', 'Made-up Brand');
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $path = $this->path();
        $writer = new XlsxWriter($spreadsheet);
        // The library calculates the formulas when it writes, as a spreadsheet does when it saves
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $workbook = $this->open($path);
        [$first, $second] = $workbook->sheets();

        self::assertSame('Results 2024', $first->title);
        self::assertFalse($first->hidden);
        self::assertSame(4, $first->frozenPane?->firstUnfrozenRow);
        self::assertSame(1, $first->frozenPane->firstUnfrozenColumn);
        self::assertCount(1, $first->tables);
        self::assertSame('ResultsTable', $first->tables[0]->name);
        self::assertSame('A3:G6', $first->tables[0]->range->toA1());
        self::assertSame(3, $first->tables[0]->headerRow);
        self::assertSame('Lists', $second->title);
        self::assertTrue($second->hidden);
        self::assertSame(['C1:E1'], array_map(static fn (CellRange $range): string => $range->toA1(), $workbook->mergedRanges($first)));

        $rows = $this->rowsOf($workbook);

        self::assertSame([1, 3, 4, 5, 6], array_keys($rows));

        $count = self::cell($rows, 1, 2);
        self::assertTrue($count->isFormula);
        self::assertSame(3, $count->value);

        self::assertSame(['Puzzle', 'Pieces', 'Finished', 'Time', 'Month', 'No box?', 'Share'], array_column($rows[3]->cells, 'value'));

        self::assertSame('Made-up Meadow', self::cell($rows, 4, 1)->value);
        self::assertSame('https://example.test/meadow', self::cell($rows, 4, 1)->hyperlink);
        self::assertSame(500, self::cell($rows, 4, 2)->value);

        $finished = self::cell($rows, 4, 3);
        self::assertSame(CellKind::Date, $finished->kind);
        self::assertSame('2024-03-05', $finished->asDate()->format('Y-m-d'));
        self::assertFalse($finished->yearShown);
        self::assertSame(DatePrecision::Day, $finished->precision);

        self::assertSame([CellKind::Duration, 3364, '0:56:04'], self::kindValueShown(self::cell($rows, 4, 4)));
        self::assertSame([CellKind::Duration, 91800, '25:30:00'], self::kindValueShown(self::cell($rows, 5, 4)));

        $month = self::cell($rows, 4, 5);
        self::assertSame(DatePrecision::Month, $month->precision);
        self::assertTrue($month->yearShown);
        self::assertSame('2024-03', $month->shown);

        self::assertSame([CellKind::Boolean, false, 'FALSE'], self::kindValueShown(self::cell($rows, 4, 6)));
        self::assertSame([CellKind::Boolean, true, 'TRUE'], self::kindValueShown(self::cell($rows, 5, 6)));
        self::assertSame('25%', self::cell($rows, 4, 7)->shown);

        $pace = self::cell($rows, 4, 8);
        self::assertTrue($pace->isFormula);
        self::assertSame(CellKind::Number, $pace->kind);
        self::assertEqualsWithDelta(8.918, $pace->asNumber(), 0.001);

        self::assertSame('Made-up Brand', self::cell($this->rowsOf($workbook, 2), 2, 1)->value);
    }

    public function testACellOfTheWrongKindSaysSoWhenAsked(): void
    {
        $cell = ReadCell::text('five hundred');

        self::assertSame('five hundred', $cell->asText());

        $this->expectException(\LogicException::class);
        $cell->asNumber();
    }

    public function testRowsKnowTheirLastColumn(): void
    {
        $row = new ReadRow(4, [2 => ReadCell::number(1), 9 => ReadCell::boolean(false)]);

        self::assertSame(9, $row->lastColumn());
        self::assertNull($row->cell(1));
        self::assertSame(1, $row->cell(2)?->value);
    }

    /**
     * @return array{CellKind, string|int|float|bool, string}
     */
    private static function kindValueShown(ReadCell $cell): array
    {
        self::assertNotInstanceOf(\DateTimeImmutable::class, $cell->value);

        return [$cell->kind, $cell->value, $cell->shown];
    }
}
