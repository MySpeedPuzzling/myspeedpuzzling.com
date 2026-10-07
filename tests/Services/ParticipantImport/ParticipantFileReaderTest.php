<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantImport;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantFileReader;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use SpeedPuzzling\Web\Value\ParticipantSheet;

final class ParticipantFileReaderTest extends TestCase
{
    private ParticipantFileReader $reader;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->reader = new ParticipantFileReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function delimiters(): iterable
    {
        yield 'comma' => ["name,country,round_names\nAlex Example,cz,Solo\nBea Sample,de,Pair\n", 'comma'];
        yield 'semicolon' => ["name;country;round_names\nAlex Example;cz;Solo\nBea Sample;de;Pair\n", 'semicolon'];
        yield 'tab' => ["name\tcountry\tround_names\nAlex Example\tcz\tSolo\nBea Sample\tde\tPair\n", 'tab'];
        yield 'semicolon with commas in cells' => [
            "name;country;round_names\nAlex Example;cz;Solo, Pair\nBea Sample;de;Pair, Team\nCid Demo;sk;Solo\n",
            'semicolon',
        ];
    }

    #[DataProvider('delimiters')]
    public function testCsvDelimiterIsDetected(string $content, string $separator): void
    {
        $path = $this->file($content);

        $sheet = $this->reader->read($path, ParticipantFileFormat::Csv);

        self::assertSame(['name', 'country', 'round_names'], $sheet->headers);
        self::assertSame('Alex Example', $sheet->rows[2][0]);
        self::assertSame('de', $sheet->rows[3][1]);
        self::assertSame($separator, $this->reader->detectCsvOptions($path)->separator);
        self::assertSame('UTF-8', $this->reader->detectCsvOptions($path)->encoding);
    }

    public function testSemicolonFileWithCommasInEveryCellIsNotSplitByCommas(): void
    {
        $sheet = $this->read("name;round_names;team\nAlex Example;Solo, Pair;Corner Crew\nBea Sample;Solo, Team;Edge Lords\n");

        self::assertSame(['name', 'round_names', 'team'], $sheet->headers);
        self::assertSame(['Alex Example', 'Solo, Pair', 'Corner Crew'], $sheet->rows[2]);
    }

    public function testExcelSepLineIsHonouredAndSkipped(): void
    {
        $path = $this->file("sep=,\nname;nickname,country\nAlex;Ex,cz\n");

        $sheet = $this->reader->read($path, ParticipantFileFormat::Csv);

        self::assertSame(['name;nickname', 'country'], $sheet->headers);
        self::assertSame([2 => ['Alex;Ex', 'cz']], $sheet->rows);
        self::assertSame('comma', $this->reader->detectCsvOptions($path)->separator);
    }

    public function testQuotedFieldsWithNewlinesAndDoubledQuotes(): void
    {
        $sheet = $this->read("name,note\n\"Example, Alex\",\"line one\nline two\"\n\"Bea \"\"Bee\"\" Sample\",x\n");

        self::assertSame('Example, Alex', $sheet->rows[2][0]);
        self::assertSame("line one\nline two", $sheet->rows[2][1]);
        self::assertSame('Bea "Bee" Sample', $sheet->rows[3][0], 'A doubled quote is a quote, a backslash escapes nothing');
    }

    public function testBackslashIsJustACharacter(): void
    {
        $sheet = $this->read("name,note\n\"Alex \\\",x\nBea,y\n");

        self::assertSame('Alex \\', $sheet->rows[2][0]);
        self::assertSame('Bea', $sheet->rows[3][0]);
    }

    public function testUtf8BomIsStripped(): void
    {
        $path = $this->file("\xEF\xBB\xBFname,country\nAlex Example,cz\n");

        self::assertSame(['name', 'country'], $this->reader->read($path, ParticipantFileFormat::Csv)->headers);
        self::assertSame('UTF-8', $this->reader->detectCsvOptions($path)->encoding);
    }

    public function testUtf16LittleEndianWithTabsAsExcelUnicodeText(): void
    {
        $path = $this->file("\xFF\xFE" . mb_convert_encoding("name\tcountry\nTomáš Příklad\tcz\n", 'UTF-16LE', 'UTF-8'));

        $sheet = $this->reader->read($path, ParticipantFileFormat::Csv);

        self::assertSame(['name', 'country'], $sheet->headers);
        self::assertSame('Tomáš Příklad', $sheet->rows[2][0]);
        self::assertEquals(new ParticipantFileOptions('UTF-16', 'tab'), $this->reader->detectCsvOptions($path));
    }

    public function testUtf16BigEndian(): void
    {
        $path = $this->file("\xFE\xFF" . mb_convert_encoding("name,country\nAlex Example,cz\n", 'UTF-16BE', 'UTF-8'));

        self::assertSame('Alex Example', $this->reader->read($path, ParticipantFileFormat::Csv)->rows[2][0]);
    }

    public function testWindows1250CzechNames(): void
    {
        // "Jiří Čermák;Šárka Říhová" written by Czech Excel ("CSV (oddělený středníkem)")
        $path = $this->file("jm\xE9no;zem\xEC\nJi\xF8\xED \xC8erm\xE1k;cz\n\x8A\xE1rka \xD8\xEDhov\xE1;cz\nOnd\xF8ej \x8Atv\xF8ete\xE8ka;cz\n");

        $sheet = $this->reader->read($path, ParticipantFileFormat::Csv);

        self::assertSame(['jméno', 'země'], $sheet->headers);
        self::assertSame('Jiří Čermák', $sheet->rows[2][0]);
        self::assertSame('Šárka Říhová', $sheet->rows[3][0]);
        self::assertSame('Ondřej Štvřetečka', $sheet->rows[4][0]);
        self::assertEquals(new ParticipantFileOptions('Windows-1250', 'semicolon'), $this->reader->detectCsvOptions($path));
    }

    public function testWindows1252CurlyApostropheAndWesternNames(): void
    {
        $path = $this->file("name,country\nAlex O\x92Example,ie\nAndr\xE9 M\xFCller,de\nJos\xE9 Pe\xF1a,es\n");

        $sheet = $this->reader->read($path, ParticipantFileFormat::Csv);

        self::assertSame("Alex O\u{2019}Example", $sheet->rows[2][0]);
        self::assertSame('André Müller', $sheet->rows[3][0]);
        self::assertSame('José Peña', $sheet->rows[4][0]);
        self::assertSame('Windows-1252', $this->reader->detectCsvOptions($path)->encoding);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function codePages(): iterable
    {
        // Western names with letters that are Czech ones in Windows-1250 (è = č, ò = ň, ø = ř)
        yield 'French' => ["H\xE9l\xE8ne Lef\xE8vre", 'Hélène Lefèvre', 'Windows-1252'];
        yield 'Italian' => ["Niccol\xF2", 'Niccolò', 'Windows-1252'];
        yield 'Danish' => ["S\xF8ren", 'Søren', 'Windows-1252'];
        yield 'British fee' => ["Alex Example \xA315", 'Alex Example £15', 'Windows-1252'];
        // Central European names
        yield 'Czech' => ["\xD8eho\xF8 \xC8erm\xE1k", 'Řehoř Čermák', 'Windows-1250'];
        yield 'Czech with rare letters' => ["\x8A\x9Dastn\xFD", 'Šťastný', 'Windows-1250'];
        yield 'Polish' => ["\xA3ukasz", 'Łukasz', 'Windows-1250'];
        yield 'Polish with ę and ń' => ["J\xEAdrzej Kami\xF1ski \xA3ukasz", 'Jędrzej Kamiński Łukasz', 'Windows-1250'];
        yield 'Spanish' => ["Mu\xF1oz Pe\xF1a", 'Muñoz Peña', 'Windows-1252'];
    }

    #[DataProvider('codePages')]
    public function testWindowsCodePageIsToldFromTheLetters(string $bytes, string $name, string $encoding): void
    {
        $path = $this->file("name,country\n" . $bytes . ",xx\n");

        self::assertSame($name, $this->reader->read($path, ParticipantFileFormat::Csv)->rows[2][0]);
        self::assertSame($encoding, $this->reader->detectCsvOptions($path)->encoding);
    }

    public function testWesternNamesTogetherStayWindows1252(): void
    {
        $path = $this->file("name;country\nH\xE9l\xE8ne Lef\xE8vre;fr\nNiccol\xF2;it\nS\xF8ren;dk\n");

        $sheet = $this->reader->read($path, ParticipantFileFormat::Csv);

        self::assertSame(['Hélène Lefèvre', 'Niccolò', 'Søren'], array_column($sheet->rows, 0));
    }

    public function testEncodingAndSeparatorCanBeOverridden(): void
    {
        $path = $this->file("name;country\nJi\xF8\xED;cz\n");

        $sheet = $this->reader->read($path, ParticipantFileFormat::Csv, 0, new ParticipantFileOptions('Windows-1252', 'comma'));

        self::assertSame(['name;country'], $sheet->headers);
        self::assertSame('Jiøí;cz', $sheet->rows[2][0]);
    }

    public function testLoneCarriageReturnLineEnds(): void
    {
        $sheet = $this->read("name,country\rAlex Example,cz\rBea Sample,de\r");

        self::assertSame(['name', 'country'], $sheet->headers);
        self::assertSame([2 => ['Alex Example', 'cz'], 3 => ['Bea Sample', 'de']], $sheet->rows);
    }

    public function testEmptyRowsAreLeftOutAndRowNumbersStay(): void
    {
        $sheet = $this->read("name,country\nAlex Example,cz\n,\n\nBea Sample,de\n \u{00A0}, \n,,\n\n");

        self::assertSame([2, 5], array_keys($sheet->rows));
        self::assertSame(['Bea Sample', 'de'], $sheet->rows[5]);
    }

    public function testOneOddRowDoesNotOutvoteTheSeparatorOfAllOthers(): void
    {
        $sheet = $this->read("name,country,note\nAlex Example,cz,a;b;c\nBea Sample,de,\nCid Demo,sk,\n");

        self::assertSame(['name', 'country', 'note'], $sheet->headers);
        self::assertSame('a;b;c', $sheet->rows[2][2]);
    }

    public function testNonBreakingSpacesAreTrimmed(): void
    {
        $sheet = $this->read("name,country\n\u{00A0}Alex Example\u{00A0} , cz\n");

        self::assertSame(['Alex Example', 'cz'], $sheet->rows[2]);
    }

    public function testTitleRowAboveTheHeaderIsSkipped(): void
    {
        $sheet = $this->read("Registration list\n\nname,country\nAlex Example,cz\n");

        self::assertSame(['name', 'country'], $sheet->headers);
        self::assertSame([4 => ['Alex Example', 'cz']], $sheet->rows);
    }

    public function testRowsAreAsWideAsTheWidestRow(): void
    {
        $sheet = $this->read("name,country\nAlex Example,cz,extra\nBea Sample\n");

        self::assertSame(['name', 'country', ''], $sheet->headers);
        self::assertSame(['Bea Sample', '', ''], $sheet->rows[3]);
    }

    public function testCsvIsOneSheet(): void
    {
        $sheets = $this->reader->sheets($this->file("name,country\nAlex Example,cz\n\n"), ParticipantFileFormat::Csv);

        self::assertCount(1, $sheets);
        self::assertSame(0, $sheets[0]->index);
        self::assertSame('', $sheets[0]->name);
        self::assertFalse($sheets[0]->hidden);
        self::assertSame(2, $sheets[0]->rows);
    }

    public function testXlsxValuesFormulasAndMergedCells(): void
    {
        $spreadsheet = new Spreadsheet();
        $list = $spreadsheet->getActiveSheet();
        $list->setTitle('Registrations');
        $list->setCellValue('A1', 'Registration 2026');
        $list->mergeCells('A1:E1');
        $list->fromArray(['name', 'member_no', 'country', 'team_name', 'rounds'], null, 'A3');
        $list->fromArray(['Alex Example', 123, 'cz', 'Corner Crew', 'Team'], null, 'A4');
        $list->fromArray(['Bea Sample', 1.5, 'de', null, 'Team'], null, 'A5');
        $list->fromArray(['Cid Demo', 7, 'sk', null, 'Team'], null, 'A6');
        $list->fromArray(['Dee Instance', 8, 'pl', 'Edge Lords', 'Solo'], null, 'A8');
        $list->mergeCells('D4:D6');
        // A merge reaching below the list makes no rows up
        $list->mergeCells('D8:D12');
        $list->setCellValue('C8', "='Countries'!A1");

        $countries = $spreadsheet->createSheet();
        $countries->setTitle('Countries');
        $countries->setCellValue('A1', 'at');

        $sheet = $this->reader->read($this->xlsx($spreadsheet), ParticipantFileFormat::Xlsx, 0);

        self::assertSame(['name', 'member_no', 'country', 'team_name', 'rounds'], $sheet->headers);
        self::assertSame([4, 5, 6, 8], array_keys($sheet->rows));
        self::assertSame(['Alex Example', '123', 'cz', 'Corner Crew', 'Team'], $sheet->rows[4]);
        self::assertSame(['Bea Sample', '1.5', 'de', 'Corner Crew', 'Team'], $sheet->rows[5]);
        self::assertSame('Corner Crew', $sheet->rows[6][3]);
        self::assertSame('at', $sheet->rows[8][2], 'The cached value of a formula that refers to another sheet');
    }

    public function testXlsxSheetsSkipEmptyOnesAndFlagHiddenOnes(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Notes');
        $spreadsheet->getActiveSheet()->fromArray([['about', 'this file'], ['x', 'y']]);
        $spreadsheet->createSheet()->setTitle('Empty');
        $hidden = $spreadsheet->createSheet();
        $hidden->setTitle('Archive');
        $hidden->fromArray([['name', 'country'], ['Old Example', 'cz']]);
        $hidden->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $participants = $spreadsheet->createSheet();
        $participants->setTitle('Participants');
        $participants->fromArray([['name', 'country'], ['Alex Example', 'cz'], ['Bea Sample', 'de']]);

        $path = $this->xlsx($spreadsheet);
        $sheets = $this->reader->sheets($path, ParticipantFileFormat::Xlsx);

        self::assertSame(['Notes', 'Archive', 'Participants'], array_map(static fn($sheet): string => $sheet->name, $sheets));
        self::assertSame([0, 1, 2], array_map(static fn($sheet): int => $sheet->index, $sheets));
        self::assertSame([false, true, false], array_map(static fn($sheet): bool => $sheet->hidden, $sheets));
        self::assertSame(3, $sheets[2]->rows);

        $sheet = $this->reader->read($path, ParticipantFileFormat::Xlsx, 2);
        self::assertSame(['Alex Example', 'cz'], $sheet->rows[2]);

        self::assertSame('Old Example', $this->reader->read($path, ParticipantFileFormat::Xlsx, 1)->rows[2][0]);

        $this->expectException(ParticipantFileUnreadable::class);
        $this->reader->read($path, ParticipantFileFormat::Xlsx, 3);
    }

    public function testXlsxWithTooManyRowsIsRefused(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['name', 'country'], ['Alex Example', 'cz']]);
        $spreadsheet->getActiveSheet()->setCellValue('A' . (ParticipantFileReader::MAX_ROWS + 1), 'Too far');

        try {
            $this->reader->read($this->xlsx($spreadsheet), ParticipantFileFormat::Xlsx);
            self::fail('A sheet longer than the limit is refused');
        } catch (ParticipantFileUnreadable $e) {
            self::assertSame(ParticipantFileUnreadable::TOO_MANY_ROWS, $e->translationKey);
        }
    }

    public function testCsvWithTooManyRowsIsRefused(): void
    {
        $this->expectException(ParticipantFileUnreadable::class);

        $this->read("name,country\n" . str_repeat("Alex Example,cz\n", ParticipantFileReader::MAX_ROWS));
    }

    public function testColumnsBeyondTheLimitAreNotRead(): void
    {
        $sheet = $this->read(implode(',', range(1, 150)) . "\n" . implode(',', range(1, 150)) . "\n");

        self::assertCount(ParticipantFileReader::MAX_COLUMNS, $sheet->headers);
    }

    /**
     * @return iterable<string, array{string, ParticipantFileFormat}>
     */
    public static function junk(): iterable
    {
        yield 'empty csv' => ['', ParticipantFileFormat::Csv];
        yield 'only separators' => [",,,\n , \n\n", ParticipantFileFormat::Csv];
        yield 'binary as csv' => ["\x00\x01\x02\x03binary\x00\xFF\x10\x11\x12\x13\x14", ParticipantFileFormat::Csv];
        yield 'zip as csv' => ["PK\x03\x04" . str_repeat("\x00\x14", 20), ParticipantFileFormat::Csv];
        yield 'text as xlsx' => ["name,country\nAlex Example,cz\n", ParticipantFileFormat::Xlsx];
        yield 'empty xlsx' => ['', ParticipantFileFormat::Xlsx];
        yield 'zip that is no workbook' => [self::zipWith('hello.txt', 'hello'), ParticipantFileFormat::Xlsx];
        yield 'broken zip' => ["PK\x03\x04" . str_repeat('garbage', 50), ParticipantFileFormat::Xlsx];
    }

    #[DataProvider('junk')]
    public function testJunkIsUnreadableNeverAnError(string $content, ParticipantFileFormat $format): void
    {
        $path = $this->file($content);

        try {
            $this->reader->sheets($path, $format);
            self::fail('sheets() refuses junk');
        } catch (ParticipantFileUnreadable) {
        }

        $this->expectException(ParticipantFileUnreadable::class);
        $this->reader->read($path, $format);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function zipBombs(): iterable
    {
        yield 'one huge part' => [[ParticipantFileReader::MAX_UNPACKED_PART_BYTES + 1]];
        yield 'too much together' => [[18 * 1024 * 1024, 18 * 1024 * 1024, 18 * 1024 * 1024]];
    }

    /**
     * @param list<int> $partSizes
     */
    #[DataProvider('zipBombs')]
    public function testAZipBombIsRefusedBeforeItIsUnpacked(array $partSizes): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'participant-reader-bomb-');
        $this->files[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($partSizes as $index => $size) {
            $zip->addFromString(sprintf('xl/worksheets/sheet%d.xml', $index + 1), str_repeat(' ', $size));
        }
        $zip->close();

        // Tiny on disk, huge unpacked
        self::assertLessThan(500_000, (int) filesize($path));

        foreach (['sheets', 'read'] as $method) {
            try {
                $this->reader->{$method}($path, ParticipantFileFormat::Xlsx);
                self::fail($method . '() refuses a zip bomb');
            } catch (ParticipantFileUnreadable $e) {
                self::assertSame(ParticipantFileUnreadable::TOO_LARGE, $e->translationKey);
            }
        }
    }

    public function testASheetWithTooManyCellsIsRefused(): void
    {
        $columns = ParticipantFileReader::MAX_COLUMNS;
        $rows = intdiv(ParticipantFileReader::MAX_CELLS, $columns) + 1;

        $xml = '';
        for ($row = 1; $row <= $rows; $row++) {
            $xml .= '<row r="' . $row . '">';
            for ($column = 1; $column <= $columns; $column++) {
                $xml .= '<c r="' . Coordinate::stringFromColumnIndex($column) . $row . '"><v>' . $column . '</v></c>';
            }
            $xml .= '</row>';
        }

        try {
            $this->reader->read($this->xlsxWithSheetXml($xml), ParticipantFileFormat::Xlsx);
            self::fail('A sheet with more cells than a list holds is refused');
        } catch (ParticipantFileUnreadable $e) {
            self::assertSame(ParticipantFileUnreadable::TOO_LARGE, $e->translationKey);
        }
    }

    public function testOnlySoManyMergedRangesAreTakenIntoAccount(): void
    {
        $data = '<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>team</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>Alex Example</t></is></c><c r="B2" t="inlineStr"><is><t>Corner Crew</t></is></c></row>'
            . '<row r="3"><c r="A3" t="inlineStr"><is><t>Bea Sample</t></is></c></row>';

        $merges = '';
        for ($i = 0; $i < ParticipantFileReader::MAX_MERGED_RANGES; $i++) {
            $merges .= '<mergeCell ref="D' . ($i * 2 + 10) . ':D' . ($i * 2 + 11) . '"/>';
        }

        // Within the limit the team merged over both rows reaches the second one; past it, it does not
        $within = $this->reader->read($this->xlsxWithSheetXml($data, '<mergeCell ref="B2:B3"/>'), ParticipantFileFormat::Xlsx);
        self::assertSame('Corner Crew', $within->rows[3][1]);

        $past = $this->reader->read($this->xlsxWithSheetXml($data, $merges . '<mergeCell ref="B2:B3"/>'), ParticipantFileFormat::Xlsx);
        self::assertSame('', $past->rows[3][1]);
    }

    public function testEmptyWorkbookIsUnreadable(): void
    {
        $path = $this->xlsx(new Spreadsheet());

        $this->expectException(ParticipantFileUnreadable::class);
        $this->reader->sheets($path, ParticipantFileFormat::Xlsx);
    }

    private function read(string $content): ParticipantSheet
    {
        return $this->reader->read($this->file($content), ParticipantFileFormat::Csv);
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'participant-reader-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function xlsx(Spreadsheet $spreadsheet): string
    {
        $path = $this->file('');
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * A real workbook whose only sheet is replaced by the given cells (and merged ranges) - for sheets PhpSpreadsheet
     * would take long to write.
     */
    private function xlsxWithSheetXml(string $sheetData, string $mergeCells = ''): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['name', 'team']]);
        $path = $this->xlsx($spreadsheet);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetData . '</sheetData>'
            . ($mergeCells !== '' ? '<mergeCells>' . $mergeCells . '</mergeCells>' : '')
            . '</worksheet>';

        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return $path;
    }

    private static function zipWith(string $name, string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'participant-reader-zip-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString($name, $content);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
