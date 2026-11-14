<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetUnreadableReason;

/**
 * The file is untrusted: every way of being too big, broken or hostile ends in SpreadsheetUnreadable with its reason -
 * never in another exception, never in a string or a pass without a cap, never with something left in the temporary
 * folder (SpreadsheetFiles checks that after every test). Every hostile file is made in the test, small on disk.
 */
final class XlsxSafetyTest extends TestCase
{
    use SpreadsheetFiles;

    public function testAFileOverTheSizeLimitIsTooLarge(): void
    {
        $path = $this->saved($this->smallWorkbook());

        $this->assertUnreadable(SpreadsheetUnreadableReason::TooLarge, $path, new SpreadsheetLimits(maxFileBytes: 200));
    }

    public function testMoreSheetsThanAllowedIsTooManySheets(): void
    {
        $workbook = new RawXlsx();

        for ($sheet = 1; $sheet <= 4; $sheet++) {
            $workbook->sheet('Sheet ' . $sheet, RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]]));
        }

        $path = $this->saved($workbook);

        self::assertCount(4, $this->open($path, limits: new SpreadsheetLimits(maxSheets: 4))->sheets());
        $this->assertUnreadable(SpreadsheetUnreadableReason::TooManySheets, $path, new SpreadsheetLimits(maxSheets: 3));
    }

    public function testMoreRowsOnASheetThanAllowedIsTooManyRows(): void
    {
        $path = $this->saved((new RawXlsx())->sheet('Rows', RawXlsx::worksheet($this->numberRows(11))));

        self::assertCount(11, $this->rowsOf($this->open($path, limits: new SpreadsheetLimits(maxRowsPerSheet: 11))));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooManyRows, $path, new SpreadsheetLimits(maxRowsPerSheet: 10));
    }

    public function testMoreRowsInAllSheetsThanAllowedIsTooManyRows(): void
    {
        $path = $this->saved(
            (new RawXlsx())
                ->sheet('One', RawXlsx::worksheet($this->numberRows(6)))
                ->sheet('Two', RawXlsx::worksheet($this->numberRows(6))),
        );
        $workbook = $this->open($path, limits: new SpreadsheetLimits(maxRows: 10));

        self::assertCount(6, $this->rowsOf($workbook, 1));
        self::assertSame(6, $workbook->rowsRead());

        try {
            $this->rowsOf($workbook, 2);
            self::fail('Twelve rows were read with a limit of ten.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::TooManyRows, $e->reason);
        }
    }

    public function testAValueBeyondTheWidestSheetAllowedIsTooManyColumns(): void
    {
        // Column 1,000 is ALL, column 1,001 is ALM
        $fits = $this->saved((new RawXlsx())->sheet('Wide', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1'), 'ALL' => RawXlsx::n('1000')]])));
        $tooWide = $this->saved((new RawXlsx())->sheet('Wide', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')], 2 => ['ALM' => RawXlsx::n('1001')]])));
        // A cell out there that holds nothing (a border, a colour) is not a value
        $styledOnly = $this->saved((new RawXlsx())->styles([14])->sheet('Wide', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1"><v>1</v></c><c r="XFD1" s="1"/></row></sheetData>')));

        // Sixty columns are no limit of the reader's: a sheet of twenty tables side by side is read
        self::assertSame(1000, $this->rowsOf($this->open($fits))[1]->lastColumn());
        self::assertSame([1], array_keys($this->rowsOf($this->open($styledOnly))));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooManyColumns, $tooWide);
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooManyColumns, $fits, new SpreadsheetLimits(maxColumns: 999));
    }

    public function testMoreCellsThanAllowedIsTooManyCells(): void
    {
        $rows = [];

        for ($row = 1; $row <= 5; $row++) {
            $rows[$row] = ['A' => RawXlsx::n('1'), 'B' => RawXlsx::n('2'), 'C' => RawXlsx::n('3')];
        }

        $path = $this->saved((new RawXlsx())->sheet('Cells', RawXlsx::worksheet($rows)));

        self::assertCount(5, $this->rowsOf($this->open($path, limits: new SpreadsheetLimits(maxCells: 15))));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooManyCells, $path, new SpreadsheetLimits(maxCells: 14));
    }

    public function testAZipBombIsTooLarge(): void
    {
        // Three megabytes of the same byte pack to a few kilobytes: a ratio no real sheet has
        $bomb = $this->saved($this->smallWorkbook()->part('xl/worksheets/sheet9.xml', str_repeat('A', 3 * 1024 * 1024)));

        $this->assertUnreadable(SpreadsheetUnreadableReason::TooLarge, $bomb);
    }

    public function testAWorkbookThatUnpacksToMoreThanAllowedIsTooLarge(): void
    {
        $path = $this->saved($this->smallWorkbook()->part('xl/media/image1.bin', random_bytes(300_000)));

        self::assertCount(1, $this->open($path)->sheets());
        $this->assertUnreadable(SpreadsheetUnreadableReason::TooLarge, $path, new SpreadsheetLimits(maxUncompressedBytes: 200_000));
    }

    public function testAnArchiveWithTooManyEntriesIsTooLarge(): void
    {
        $workbook = $this->smallWorkbook();

        for ($picture = 1; $picture <= 30; $picture++) {
            $workbook->part(sprintf('xl/media/image%d.png', $picture), 'x');
        }

        $path = $this->saved($workbook);

        self::assertCount(1, $this->open($path)->sheets());
        $this->assertUnreadable(SpreadsheetUnreadableReason::TooLarge, $path, new SpreadsheetLimits(maxZipEntries: 20));
    }

    /**
     * @return iterable<string, array{string, string, string}> which relationship, the target it gets, the entry it is
     */
    public static function partsReachedThroughARelationship(): iterable
    {
        yield 'the shared strings under a name that is no XML' => ['sharedStrings', 'strings.bin', 'xl/strings.bin'];
        yield 'a sheet under a name that is no XML' => ['worksheet', 'worksheets/s.bin', 'xl/worksheets/s.bin'];
        yield 'the styles outside the workbook\'s folder' => ['styles', '/customXml/s.xml', 'customXml/s.xml'];
    }

    /**
     * What is checked is what is opened: a part is metered whatever its name and wherever a relationship finds it.
     */
    #[DataProvider('partsReachedThroughARelationship')]
    public function testAPartThatLiesAboutItsSizeIsDamagedWhateverItsNameAndPlace(string $kind, string $target, string $entry): void
    {
        // Half a megabyte that the archive calls 1,000 bytes
        $padding = str_repeat('<!--' . str_repeat('x', 4_993) . '-->', 100) . 'xyz';
        $content = match ($kind) {
            'sharedStrings' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $padding . '<si><t>text</t></si></sst>',
            'worksheet' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $padding . '<sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>',
            default => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $padding . '<cellXfs><xf numFmtId="0"/></cellXfs></styleSheet>',
        };

        $workbook = (new RawXlsx())->sharedStrings(['text'])->sheet('Sheet', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(0)]]));
        $relationships = $workbook->parts()['xl/_rels/workbook.xml.rels'];
        $path = $this->saved(
            $workbook
                ->part('xl/_rels/workbook.xml.rels', strtr($relationships, match ($kind) {
                    'sharedStrings' => ['Target="sharedStrings.xml"' => 'Target="' . $target . '"'],
                    'worksheet' => ['Target="worksheets/sheet1.xml"' => 'Target="' . $target . '"'],
                    default => ['Target="styles.xml"' => 'Target="' . $target . '"'],
                }))
                ->part($entry, $content),
        );

        // Honest, the workbook reads
        self::assertSame('text', self::cell($this->rowsOf($this->open($path)), 1, 1)->value);

        RawXlsx::declareSize($path, strlen($content), 1_000);

        try {
            $this->rowsOf($this->open($path));
            self::fail('A part that is longer than the archive says was read.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason, $e->getMessage());
            self::assertStringContainsString('longer than the archive says', $e->getMessage());
        }
    }

    public function testATabThatIsNoWorksheetIsNeverOpenedWhateverItPointsAt(): void
    {
        $bomb = str_repeat('A', 3_000_011);
        $workbook = (new RawXlsx())->sheet('Real', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]]));
        $parts = $workbook->parts();
        $path = $this->saved(
            $workbook
                ->part('xl/charts/x.bin', $bomb)
                ->part('xl/workbook.xml', str_replace('</sheets>', '<sheet name="Chart" sheetId="9" r:id="rIdChart"/></sheets>', $parts['xl/workbook.xml']))
                ->part('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdChart" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chartsheet" Target="charts/x.bin"/></Relationships>', $parts['xl/_rels/workbook.xml.rels'])),
        );
        RawXlsx::declareSize($path, strlen($bomb), 1_000);

        // The archive lies about the chart sheet's part - nobody opens it, so nobody is lied to
        $opened = $this->open($path, limits: new SpreadsheetLimits(maxReadBytes: 100_000));

        self::assertSame(['Real'], array_column($opened->sheets(), 'title'));
        self::assertSame(1, self::cell($this->rowsOf($opened), 1, 1)->value);
    }

    public function testEveryPassOverEveryPartCountsAgainstOneBudget(): void
    {
        // 150 KB before the first row - and three sheets that all point to this one part
        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . str_repeat('<!--' . str_repeat('x', 1_493) . '-->', 100) . '<sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData></worksheet>';
        $workbook = (new RawXlsx())->sheet('One', $sheet)->sheet('Two', $sheet)->sheet('Three', $sheet);
        $relationships = $workbook->parts()['xl/_rels/workbook.xml.rels'];
        $path = $this->saved($workbook->part('xl/_rels/workbook.xml.rels', strtr($relationships, ['worksheets/sheet2.xml' => 'worksheets/sheet1.xml', 'worksheets/sheet3.xml' => 'worksheets/sheet1.xml'])));

        self::assertCount(3, $this->open($path)->sheets());
        // Opening looks into the part three times: the budget counts it three times
        $this->assertUnreadable(SpreadsheetUnreadableReason::TooLarge, $path, new SpreadsheetLimits(maxReadBytes: 400_000));

        // Rows that hold nothing are not free either
        $empty = $this->saved((new RawXlsx())->sheet('Empty rows', RawXlsx::worksheetAround('<sheetData>' . str_repeat('<row/>', 100_000) . '<row><c><v>1</v></c></row></sheetData>')));

        self::assertSame([100_001], array_keys($this->rowsOf($this->open($empty))));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooLarge, $empty, new SpreadsheetLimits(maxReadBytes: 300_000));
    }

    public function testACellLongerThanACellCanBeIsRefusedWhileItArrives(): void
    {
        // Five megabytes in one cell: a few kilobytes on disk
        $longCell = $this->saved((new RawXlsx())->sheet('Long', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>' . str_repeat('a', 5_000_000) . '</t></is></c></row></sheetData>')));
        $longValue = $this->saved((new RawXlsx())->sheet('Long', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1" t="str"><f>REPT("a",5000000)</f><v>' . str_repeat('a', 5_000_000) . '</v></c></row></sheetData>')));

        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::CellTooLarge, $longCell, self::packedWell());
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::CellTooLarge, $longValue, self::packedWell());

        // As long as a spreadsheet's cell can be: read
        $longest = str_repeat('ž', 32_767);
        $rows = $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Longest', RawXlsx::worksheet([1 => ['A' => RawXlsx::inline($longest)]]))), limits: self::packedWell()));

        self::assertSame($longest, self::cell($rows, 1, 1)->value);
    }

    public function testACellOfTwentyMegabytesIsRefused(): void
    {
        $path = $this->saved((new RawXlsx())->sheet('Huge', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>' . str_repeat('a', 20_000_000) . '</t></is></c></row></sheetData>')));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        try {
            $this->rowsOf($this->open($path, limits: self::packedWell()));
            self::fail('A cell of twenty megabytes was read.');
        } catch (SpreadsheetUnreadable $e) {
            // Refused before the XML parser has it: its own limit of ten megabytes a text is never reached
            self::assertSame(SpreadsheetUnreadableReason::CellTooLarge, $e->reason);
        }

        self::assertLessThan(2 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function testARowOfManyLargeCellsIsRefusedBeforeItIsBuilt(): void
    {
        $cells = '';

        // Twenty cells of 100 KB each: every one fits a cell, together they are no row
        for ($column = 1; $column <= 20; $column++) {
            $cells .= '<c t="inlineStr"><is><t>' . str_repeat(chr(64 + $column), 100_000) . '</t></is></c>';
        }

        $this->assertUnreadableWhileStreaming(
            SpreadsheetUnreadableReason::CellTooLarge,
            $this->saved((new RawXlsx())->sheet('Heavy', RawXlsx::worksheetAround('<sheetData><row r="1">' . $cells . '</row></sheetData>'))),
            self::packedWell(),
        );

        // The same weight through the shared strings: one long text, pointed to by every cell of a row
        $pointers = [];

        for ($column = 'A'; $column !== 'U'; $column = str_increment($column)) {
            $pointers[$column] = RawXlsx::s(0);
        }

        $this->assertUnreadableWhileStreaming(
            SpreadsheetUnreadableReason::CellTooLarge,
            $this->saved((new RawXlsx())->sharedStrings([str_repeat('b', 100_000)])->sheet('Heavy', RawXlsx::worksheet([1 => $pointers]))),
            self::packedWell(),
        );
    }

    public function testASharedStringOfAHundredThousandRunsIsReadInOneStraightPass(): void
    {
        $runs = '<si>' . str_repeat('<r><rPr><b/></rPr><t>a</t></r>', 100_000) . '</si>';
        $emptyRuns = '<si>' . str_repeat('<r><t/></r>', 200_000) . '<r><t>end</t></r></si>';
        $path = $this->saved(
            (new RawXlsx())->sharedStrings([$runs, $emptyRuns, 'plain'])->sheet('Runs', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(0), 'B' => RawXlsx::s(1), 'C' => RawXlsx::s(2)]])),
        );
        $this->rowsOf($this->open($this->saved((new RawXlsx())->sharedStrings(['warm-up'])->sheet('Warm-up', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(0)]])))));

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = hrtime(true);

        $rows = $this->rowsOf($this->open($path, limits: self::packedWell()));

        self::assertSame(str_repeat('a', 100_000), self::cell($rows, 1, 1)->value);
        self::assertSame('end', self::cell($rows, 1, 2)->value);
        self::assertSame('plain', self::cell($rows, 1, 3)->value);
        // Generous bounds: it takes a fraction of a second and no more memory than the string itself
        self::assertLessThan(10.0, (hrtime(true) - $started) / 1e9);
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);

        // One run more than a cell can hold
        $tooMany = '<si>' . str_repeat('<r><t>a</t></r>', 131_073) . '</si>';
        $this->assertUnreadableWhileStreaming(
            SpreadsheetUnreadableReason::CellTooLarge,
            $this->saved((new RawXlsx())->sharedStrings([$tooMany])->sheet('Runs', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(0)]]))),
            self::packedWell(),
        );
    }

    public function testTheDistinctTextsOfAWorkbookHaveCapsOfTheirOwn(): void
    {
        $path = $this->saved((new RawXlsx())->sharedStrings(['aaaa', 'bbbb', 'cccc', 'dddd'])->sheet('Strings', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(3)]])));

        self::assertCount(1, $this->rowsOf($this->open($path, limits: new SpreadsheetLimits(maxSharedStrings: 4, maxSharedStringBytes: 16))));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooLarge, $path, new SpreadsheetLimits(maxSharedStrings: 3));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooLarge, $path, new SpreadsheetLimits(maxSharedStringBytes: 15));

        // Not the import's cell budget: a late job with two cells left still reads all four texts for its one cell
        $late = (new SpreadsheetLimits())->withConsumed(49_000, 999_998);

        self::assertSame(2, $late->maxCells);
        self::assertSame('dddd', self::cell($this->rowsOf($this->open($path, limits: $late)), 1, 1)->value);
    }

    public function testFiftyThousandTabsAreCountedNotWorkedThrough(): void
    {
        $tabs = '';

        for ($tab = 1; $tab <= 50_000; $tab++) {
            // Chart sheets, macro sheets, tabs that point nowhere: every one is a tab
            $tabs .= sprintf('<sheet name="Tab %1$d" sheetId="%1$d" r:id="rIdNone%1$d"/>', $tab);
        }

        $workbook = (new RawXlsx())->sheet('Real', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]]));
        $path = $this->saved($workbook->part('xl/workbook.xml', str_replace('</sheets>', $tabs . '</sheets>', $workbook->parts()['xl/workbook.xml'])));
        $started = hrtime(true);

        $this->assertUnreadable(SpreadsheetUnreadableReason::TooManySheets, $path);

        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
    }

    public function testTwoEntriesOfOneNameAreDamaged(): void
    {
        $path = $this->saved($this->smallWorkbook()->part('xl/worksheets/sheet7.xml', '<worksheet/>'));
        $bytes = (string) file_get_contents($path);

        // The second sheet takes the first one's name, in its own header and in the archive's directory
        self::assertSame(2, substr_count($bytes, 'xl/worksheets/sheet7.xml'));
        file_put_contents($path, str_replace('xl/worksheets/sheet7.xml', 'xl/worksheets/sheet1.xml', $bytes));

        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $path);
    }

    public function testAttributesThatAreNoNumbersAreDamagedNotGuessed(): void
    {
        $styled = static fn (string $style): RawXlsx => (new RawXlsx())->styles([14])->sheet('Sheet', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1" s="' . $style . '"><v>45356</v></c></row></sheetData>'));

        self::assertSame('2024-03-05', self::cell($this->rowsOf($this->open($this->saved($styled('1')))), 1, 1)->shown);
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::Damaged, $this->saved($styled('1e9')));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::Damaged, $this->saved($styled('-1')));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::Damaged, $this->saved($styled('99999999999999999999')));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::Damaged, $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData><row r="two"><c><v>1</v></c></row></sheetData>'))));
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved(
            (new RawXlsx())->sheet('Sheet', RawXlsx::worksheet([], '<sheetViews><sheetView><pane ySplit="many" state="frozen"/></sheetView></sheetViews>')),
        ));
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved(
            $this->smallWorkbook()->part('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs><xf numFmtId="fourteen"/></cellXfs></styleSheet>'),
        ));
    }

    public function testTheFormatsOfConditionalFormattingAreNotTheCellsFormats(): void
    {
        // Number 164 is a month for the cells - and, further down, something else for a conditional format
        $styles = '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="mmmm\ yyyy"/></numFmts>'
            . '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs>'
            . '<dxfs count="1"><dxf><numFmt numFmtId="164" formatCode="0.00"/></dxf></dxfs>'
            . '</styleSheet>';

        $rows = $this->rowsOf($this->open($this->saved(
            (new RawXlsx())->sheet('Sheet', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('45356', 1)]]))->part('xl/styles.xml', $styles),
        )));

        self::assertSame('2024-03', self::cell($rows, 1, 1)->shown);
    }

    public function testAFolderThatCanNotBeWrittenIsTheServersFaultNotTheFiles(): void
    {
        $strings = [];
        $rows = [];

        for ($index = 0; $index < 5_500; $index++) {
            $strings[] = 'Made-up puzzle ' . $index;
            $rows[$index + 1] = ['A' => RawXlsx::s($index)];
        }

        $path = $this->saved((new RawXlsx())->sharedStrings($strings)->sheet('Many', RawXlsx::worksheet($rows)));
        // A "folder" under a file: nothing can be made there, whoever runs the test
        $reader = new SpreadsheetReader($this->file('not a folder', 'txt') . '/tmp');
        $workbook = $reader->open($path, SpreadsheetFormat::Xlsx);

        try {
            iterator_to_array($workbook->rows($workbook->sheets()[0]), false);
            self::fail('The shared strings were kept without a place to keep them.');
        } catch (SpreadsheetReaderFailed $e) {
            self::assertStringContainsString('temporary folder', $e->getMessage());
        } finally {
            $workbook->close();
        }

        // Not a row was handed out - and the file is not called damaged
        self::assertSame(0, $workbook->rowsRead());
    }

    public function testAFileThatIsNoZipIsNotASpreadsheet(): void
    {
        $this->assertUnreadable(SpreadsheetUnreadableReason::NotASpreadsheet, $this->file('Puzzle,Pieces' . "\n" . 'Made-up Meadow,500' . "\n", 'xlsx'));
        $this->assertUnreadable(SpreadsheetUnreadableReason::NotASpreadsheet, $this->file("\x89PNG\r\n\x1a\n" . random_bytes(200), 'xlsx'));
        $this->assertUnreadable(SpreadsheetUnreadableReason::NotASpreadsheet, $this->file('', 'xlsx'));
    }

    public function testATruncatedWorkbookIsDamaged(): void
    {
        $path = $this->saved($this->smallWorkbook());
        $bytes = (string) file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, (int) (strlen($bytes) * 0.6)));

        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $path);
    }

    public function testAFileThatIsMissingIsTheServersFaultNotTheFiles(): void
    {
        $this->expectException(SpreadsheetReaderFailed::class);
        $this->open($this->path());
    }

    /**
     * @return iterable<string, array{array<string, string>, SpreadsheetUnreadableReason}>
     */
    public static function otherArchives(): iterable
    {
        yield 'an OpenDocument spreadsheet' => [['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet', 'content.xml' => '<office:document-content/>'], SpreadsheetUnreadableReason::UnsupportedFormat];
        yield 'a Numbers file' => [['Index/Document.iwa' => 'x', 'Metadata/Properties.plist' => 'x'], SpreadsheetUnreadableReason::UnsupportedFormat];
        yield 'a binary workbook' => [['[Content_Types].xml' => '<Types/>', 'xl/workbook.bin' => 'x'], SpreadsheetUnreadableReason::UnsupportedFormat];
        yield 'a text document renamed' => [['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>'], SpreadsheetUnreadableReason::NotASpreadsheet];
        yield 'a text document with its relationships' => [[
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
        ], SpreadsheetUnreadableReason::NotASpreadsheet];
        yield 'a binary workbook with its relationships' => [[
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.bin"/></Relationships>',
            'xl/workbook.bin' => 'x',
        ], SpreadsheetUnreadableReason::UnsupportedFormat];
        yield 'any other zip' => [['holiday.txt' => 'x'], SpreadsheetUnreadableReason::NotASpreadsheet];
        yield 'a workbook without its relationships' => [['xl/workbook.xml' => '<workbook/>'], SpreadsheetUnreadableReason::Damaged];
    }

    /**
     * @param array<string, string> $entries
     */
    #[DataProvider('otherArchives')]
    public function testAnArchiveThatIsNoWorkbookSaysWhatItIs(array $entries, SpreadsheetUnreadableReason $reason): void
    {
        $path = $this->path();
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        $this->assertUnreadable($reason, $path);
    }

    public function testAnOldWorkbookIsUnsupportedAndAnEncryptedOneAsksForItsPassword(): void
    {
        $compoundFile = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);

        $this->assertUnreadable(
            SpreadsheetUnreadableReason::UnsupportedFormat,
            $this->file($compoundFile . mb_convert_encoding('Workbook', 'UTF-16LE', 'ASCII') . str_repeat("\0", 400), 'xlsx'),
        );
        // What a spreadsheet writes for "protect with a password": the same container around an encrypted package
        $this->assertUnreadable(
            SpreadsheetUnreadableReason::PasswordProtected,
            $this->file($compoundFile . mb_convert_encoding('EncryptionInfo', 'UTF-16LE', 'ASCII') . str_repeat("\0", 90) . mb_convert_encoding('EncryptedPackage', 'UTF-16LE', 'ASCII') . str_repeat("\0", 400), 'xlsx'),
        );
    }

    public function testAnEncryptedZipEntryAsksForItsPassword(): void
    {
        $path = $this->saved($this->smallWorkbook());
        $zip = new \ZipArchive();
        $zip->open($path);

        if ($zip->setEncryptionName('xl/worksheets/sheet1.xml', \ZipArchive::EM_AES_256, 'made-up') === false) {
            $zip->close();
            self::markTestSkipped('This libzip can not encrypt.');
        }

        $zip->close();

        $this->assertUnreadable(SpreadsheetUnreadableReason::PasswordProtected, $path);
    }

    public function testAnEntryNameThatLeavesTheArchiveIsDamaged(): void
    {
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved($this->smallWorkbook()->part('../../outside.xml', '<x/>')));
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved($this->smallWorkbook()->part('xl/../../outside.xml', '<x/>')));
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved($this->smallWorkbook()->part('/etc/outside.xml', '<x/>')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function partsWithAnEntity(): iterable
    {
        yield 'the shared strings' => ['xl/sharedStrings.xml'];
        yield 'a sheet' => ['xl/worksheets/sheet1.xml'];
        yield 'the workbook' => ['xl/workbook.xml'];
        yield 'the styles' => ['xl/styles.xml'];
        yield 'the relationships' => ['xl/_rels/workbook.xml.rels'];
    }

    #[DataProvider('partsWithAnEntity')]
    public function testAnExternalEntityIsNeverResolved(string $entry): void
    {
        $secret = $this->file('TOP-SECRET-' . bin2hex(random_bytes(8)), 'txt');
        $doctype = sprintf('<!DOCTYPE x [<!ENTITY leak SYSTEM "file://%s"><!ENTITY big "aaaaaaaaaa">]>', $secret);

        $parts = [
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si><t>&leak;</t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&leak;</t></is></c></row></sheetData></worksheet>',
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="&leak;" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts><numFmt numFmtId="164" formatCode="&leak;"/></numFmts></styleSheet>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="&leak;"/></Relationships>',
        ];

        $path = $this->saved(
            (new RawXlsx())
                ->sharedStrings(['safe'])
                ->sheet('Sheet', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(0)]]))
                ->part($entry, '<?xml version="1.0" encoding="UTF-8"?>' . $doctype . $parts[$entry]),
        );

        try {
            $workbook = $this->open($path);
            $this->rowsOf($workbook);
            self::fail('A part with a document type was read.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason);
            self::assertStringNotContainsString('TOP-SECRET', $e->getMessage());
            self::assertStringNotContainsString('TOP-SECRET', (string) $e->getPrevious()?->getMessage());
        }
    }

    public function testASheetThatIsNotWellFormedIsDamagedWhereItBreaks(): void
    {
        $broken = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1"><v>1</v></c></row><row r="2"><c r="A2"><v>2</v></c></row><row r="3"><c r="A3"><v>3</v>';
        $path = $this->saved($this->smallWorkbook()->part('xl/worksheets/sheet1.xml', $broken));

        $workbook = $this->open($path);
        $rows = $workbook->rows($workbook->sheets()[0]);

        // The rows before the break are handed out; the row that is cut short is not
        self::assertSame(1, $rows->current()->number);
        $rows->next();
        self::assertSame(2, $rows->current()->number);

        try {
            $rows->next();
            self::fail('A sheet cut short was read on.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason);
        }

        self::assertFalse($rows->valid());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenSheets(): iterable
    {
        yield 'cells of a row out of order' => ['<row r="1"><c r="B1"><v>1</v></c><c r="A1"><v>2</v></c></row>'];
        yield 'the same cell twice' => ['<row r="1"><c r="A1"><v>1</v></c><c r="A1"><v>2</v></c></row>'];
        yield 'a cell reference no sheet has' => ['<row r="1"><c r="1A"><v>1</v></c></row>'];
        yield 'a column beyond the last one' => ['<row r="1"><c r="XFE1"><v>1</v></c></row>'];
        yield 'a row beyond the last one' => ['<row r="1048577"><c r="A1048577"><v>1</v></c></row>'];
        yield 'a row number of nonsense' => ['<row r="999999999999"><c><v>1</v></c></row>'];
        yield 'a shared string that is not there' => ['<row r="1"><c r="A1" t="s"><v>99</v></c></row>'];
    }

    #[DataProvider('brokenSheets')]
    public function testASheetThatMakesNoSenseIsDamaged(string $rows): void
    {
        $path = $this->saved((new RawXlsx())->sharedStrings(['only one'])->sheet('Broken', RawXlsx::worksheetAround('<sheetData>' . $rows . '</sheetData>')));

        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::Damaged, $path);
    }

    public function testAWorkbookWithoutSheetsOrWithAMissingSheetPartIsDamaged(): void
    {
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved(new RawXlsx()));
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved($this->smallWorkbook()->part('xl/worksheets/sheet1.xml', null)));
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->saved($this->smallWorkbook()->part('xl/workbook.xml', 'not xml at all')));
    }

    public function testTwoSheetsOfTheSameNameAreDamaged(): void
    {
        $this->assertUnreadable(
            SpreadsheetUnreadableReason::Damaged,
            $this->saved((new RawXlsx())->sheet('Same', RawXlsx::worksheet([]))->sheet('Same', RawXlsx::worksheet([]))),
        );
    }

    public function testAnXlsxGivenAsTextIsUnsupported(): void
    {
        $path = $this->saved($this->smallWorkbook());

        try {
            $this->open($path, SpreadsheetFormat::Csv);
            self::fail('A workbook was read as text.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::UnsupportedFormat, $e->reason);
        }
    }

    public function testManySharedStringsAreKeptInTheTemporaryFolderAndRemovedOnClose(): void
    {
        $strings = [];
        $rows = [];

        for ($index = 0; $index < 6_000; $index++) {
            $strings[] = 'Made-up puzzle ' . $index;
            $rows[$index + 1] = ['A' => RawXlsx::s($index), 'B' => RawXlsx::s(5_999 - $index)];
        }

        $workbook = $this->open($this->saved((new RawXlsx())->sharedStrings($strings)->sheet('Many', RawXlsx::worksheet($rows))));

        // Nothing is written before a cell asks for a text
        self::assertSame([], glob($this->folder . '/tmp/*'));

        $read = $this->rowsOf($workbook);

        // More strings than stay in memory: they went to a file in the reader's own folder, which only the server reads
        $folders = glob($this->folder . '/tmp/msp-spreadsheet-*');
        self::assertIsArray($folders);
        self::assertCount(1, $folders);
        self::assertSame(0700, fileperms($folders[0]) & 0777);
        self::assertCount(2, (array) glob($folders[0] . '/*'));

        self::assertCount(6_000, $read);
        self::assertSame('Made-up puzzle 0', self::cell($read, 1, 1)->value);
        self::assertSame('Made-up puzzle 5999', self::cell($read, 1, 2)->value);
        self::assertSame('Made-up puzzle 5999', self::cell($read, 6_000, 1)->value);

        $workbook->close();

        self::assertSame([], glob($this->folder . '/tmp/*'));
    }

    /**
     * The limits for a test whose file is one character a million times: such a file packs like a zip bomb, and it is
     * the cap behind that check the test is about.
     */
    private static function packedWell(): SpreadsheetLimits
    {
        return new SpreadsheetLimits(maxCompressionRatio: 1_000_000);
    }

    private function smallWorkbook(): RawXlsx
    {
        return (new RawXlsx())->sharedStrings(['Made-up Meadow'])->sheet('Sheet', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(0), 'B' => RawXlsx::n('500')]]));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function numberRows(int $count): array
    {
        $rows = [];

        for ($row = 1; $row <= $count; $row++) {
            $rows[$row] = ['A' => RawXlsx::n((string) $row)];
        }

        return $rows;
    }

    private function assertUnreadable(SpreadsheetUnreadableReason $reason, string $path, SpreadsheetLimits $limits = new SpreadsheetLimits()): void
    {
        try {
            $this->open($path, limits: $limits);
            self::fail(sprintf('The file was opened; expected "%s".', $reason->value));
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    private function assertUnreadableWhileStreaming(SpreadsheetUnreadableReason $reason, string $path, SpreadsheetLimits $limits = new SpreadsheetLimits()): void
    {
        $workbook = $this->open($path, limits: $limits);

        try {
            $this->rowsOf($workbook);
            self::fail(sprintf('The sheet was read; expected "%s".', $reason->value));
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}
