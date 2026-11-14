<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetUnreadableReason;

/**
 * The file is untrusted: every way of being too big, broken or hostile ends in SpreadsheetUnreadable with its reason -
 * never in another exception, never with something left in the temporary folder (SpreadsheetFiles checks that after
 * every test).
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
        // The same sheet read again counts once
        self::assertCount(6, $this->rowsOf($workbook, 1));

        try {
            $this->rowsOf($workbook, 2);
            self::fail('Twelve rows were read with a limit of ten.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::TooManyRows, $e->reason);
        }
    }

    public function testAValueBeyondTheLastAllowedColumnIsTooManyColumns(): void
    {
        // Column 60 is BH, column 61 is BI
        $fits = $this->saved((new RawXlsx())->sheet('Wide', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1'), 'BH' => RawXlsx::n('60')]])));
        $tooWide = $this->saved((new RawXlsx())->sheet('Wide', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')], 2 => ['BI' => RawXlsx::n('61')]])));
        // A cell out there that holds nothing (a border, a colour) is not a value
        $styledOnly = $this->saved((new RawXlsx())->styles([14])->sheet('Wide', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1"><v>1</v></c><c r="XFD1" s="1"/></row></sheetData>')));

        self::assertSame(60, $this->rowsOf($this->open($fits))[1]->lastColumn());
        self::assertSame([1], array_keys($this->rowsOf($this->open($styledOnly))));
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooManyColumns, $tooWide);
        $this->assertUnreadableWhileStreaming(SpreadsheetUnreadableReason::TooManyColumns, $fits, new SpreadsheetLimits(maxColumns: 59));
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

    public function testAPartLongerThanItsHeaderSaysIsDamaged(): void
    {
        $path = $this->saved($this->smallWorkbook()->part('xl/worksheets/sheet7.xml', '<r>' . str_repeat('<a/>', 5_000) . '</r>'));
        $bytes = (string) file_get_contents($path);
        // The unpacked size stands in the entry's header and in the archive's directory: say 40 bytes in both
        $real = pack('V', 3 + 4 * 5_000 + 4);
        self::assertSame(2, substr_count($bytes, $real));
        file_put_contents($path, str_replace($real, pack('V', 40), $bytes));

        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $path);
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

    public function testAFileThatIsMissingIsDamaged(): void
    {
        $this->assertUnreadable(SpreadsheetUnreadableReason::Damaged, $this->path());
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

    public function testWhatALibraryThrowsIsWrappedAndKept(): void
    {
        $path = $this->saved((new RawXlsx())->sharedStrings(['only one'])->sheet('Broken', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(99)]])));
        $workbook = $this->open($path);

        try {
            $this->rowsOf($workbook);
            self::fail('A shared string that is not there was read.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason);
            self::assertNotNull($e->getPrevious());
            self::assertStringStartsWith('OpenSpout\\', $e->getPrevious()::class);
        }
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

        // More strings than stay in memory: they went to a file in the reader's own folder
        $folders = glob($this->folder . '/tmp/msp-spreadsheet-*');
        self::assertIsArray($folders);
        self::assertCount(1, $folders);
        self::assertNotSame([], glob($folders[0] . '/shared-strings-*'));

        $read = $this->rowsOf($workbook);

        self::assertCount(6_000, $read);
        self::assertSame('Made-up puzzle 0', self::cell($read, 1, 1)->value);
        self::assertSame('Made-up puzzle 5999', self::cell($read, 1, 2)->value);
        self::assertSame('Made-up puzzle 5999', self::cell($read, 6_000, 1)->value);

        $workbook->close();

        self::assertSame([], glob($this->folder . '/tmp/*'));
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
