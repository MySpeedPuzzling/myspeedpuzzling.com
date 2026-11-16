<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Value\Spreadsheet\CellKind;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetUnreadableReason;

final class CsvReaderTest extends TestCase
{
    use SpreadsheetFiles;

    /**
     * @return iterable<string, array{string}>
     */
    public static function delimiters(): iterable
    {
        yield 'comma' => ["Name,Brand,Pieces\nMade-up Meadow,Invented,500\nImaginary Alps,Invented,1000\n"];
        yield 'semicolon' => ["Name;Brand;Pieces\nMade-up Meadow;Invented;500\nImaginary Alps;Invented;1000\n"];
        yield 'tab' => ["Name\tBrand\tPieces\nMade-up Meadow\tInvented\t500\nImaginary Alps\tInvented\t1000\n"];
        yield 'semicolon with commas in the cells' => ["Name;Brand;Pieces\nMade-up Meadow;Invented;500\nImaginary Alps;Invented;1000\nA, B, C and D;Invented, Ltd.;300\n"];
        yield 'a title line above' => ["My puzzles\nName;Brand;Pieces\nMade-up Meadow;Invented;500\nImaginary Alps;Invented;1000\n"];
        yield 'Excel names the delimiter' => ["sep=;\nName;Brand;Pieces\nMade-up Meadow;Invented;500\nImaginary Alps;Invented;1000\n"];
        yield 'Excel names an unusual delimiter' => ["sep=|\r\nName|Brand|Pieces\r\nMade-up Meadow|Invented|500\r\nImaginary Alps|Invented|1000\r\n"];
        yield 'Windows line ends' => ["Name,Brand,Pieces\r\nMade-up Meadow,Invented,500\r\nImaginary Alps,Invented,1000\r\n"];
        yield 'old Mac line ends' => ["Name,Brand,Pieces\rMade-up Meadow,Invented,500\rImaginary Alps,Invented,1000\r"];
        yield 'no line end after the last record' => ["Name,Brand,Pieces\nMade-up Meadow,Invented,500\nImaginary Alps,Invented,1000"];
    }

    #[DataProvider('delimiters')]
    public function testTheDelimiterAndTheLineEndsAreFound(string $content): void
    {
        $rows = array_values($this->rowsOf($this->open($this->file($content), SpreadsheetFormat::Csv)));
        $header = array_search(['Name', 'Brand', 'Pieces'], array_map(self::texts(...), $rows), true);

        self::assertIsInt($header);
        self::assertSame(['Made-up Meadow', 'Invented', '500'], self::texts($rows[$header + 1]));
        self::assertSame(['Imaginary Alps', 'Invented', '1000'], self::texts($rows[$header + 2]));
    }

    public function testEveryCellIsATextExactlyAsWritten(): void
    {
        $workbook = $this->open($this->file("ID,Time,Date,Pieces,Solved\n7,0:56:4,05.03.2024,500,TRUE\n8, 1:5:9 ,,=1+1,\n"), SpreadsheetFormat::Csv);
        $sheets = $workbook->sheets();

        self::assertCount(1, $sheets);
        self::assertSame('', $sheets[0]->title);
        self::assertSame(1, $sheets[0]->position);
        self::assertFalse($sheets[0]->hidden);
        self::assertSame([], $sheets[0]->tables);
        self::assertNull($sheets[0]->frozenPane);
        self::assertSame([], $workbook->mergedRanges($sheets[0]));

        $rows = $this->rowsOf($workbook);

        self::assertSame([1, 2, 3], array_keys($rows));

        foreach ($rows as $row) {
            foreach ($row->cells as $cell) {
                self::assertSame(CellKind::Text, $cell->kind);
                self::assertSame($cell->value, $cell->shown);
                self::assertFalse($cell->isFormula);
            }
        }

        // Typing a time, a date, a number is the next layer's job
        self::assertSame(['7', '0:56:4', '05.03.2024', '500', 'TRUE'], self::texts($rows[2]));
        // Nothing is trimmed; empty cells are not there
        self::assertSame([1 => '8', 2 => ' 1:5:9 ', 4 => '=1+1'], array_map(static fn ($cell) => $cell->value, $rows[3]->cells));
    }

    public function testAByteOrderMarkAndAQuotedFirstHeaderDoNotDisturb(): void
    {
        $rows = $this->rowsOf($this->open($this->file("\xEF\xBB\xBF\"ID\",Name,\"Number of elements\"\r\n\"a1\",\"Made-up Meadow\",500\r\n"), SpreadsheetFormat::Csv));

        self::assertSame(['ID', 'Name', 'Number of elements'], self::texts($rows[1]));
        self::assertSame(['a1', 'Made-up Meadow', '500'], self::texts($rows[2]));
    }

    public function testQuotedCellsHoldDelimitersQuotesAndLineBreaks(): void
    {
        $content = "Name,Notes,Pieces\n"
            . "\"Meadow, the made-up one\",\"She said \"\"lovely\"\"\",500\n"
            . "\"Two\nlines\",\"three\r\nlines\nhere\",1000\n"
            . "Plain,,300\n";

        $rows = $this->rowsOf($this->open($this->file($content), SpreadsheetFormat::Csv));

        // A record with line breaks inside a cell is one row
        self::assertSame([1, 2, 3, 4], array_keys($rows));
        self::assertSame(['Meadow, the made-up one', 'She said "lovely"', '500'], self::texts($rows[2]));
        self::assertSame(["Two\nlines", "three\r\nlines\nhere", '1000'], self::texts($rows[3]));
        self::assertSame([1 => 'Plain', 3 => '300'], array_map(static fn ($cell) => $cell->value, $rows[4]->cells));
    }

    public function testEmptyLinesAreNotRowsButKeepTheNumbering(): void
    {
        $rows = $this->rowsOf($this->open($this->file("Name,Pieces\n\nMade-up Meadow,500\n,\n\n\nImaginary Alps,1000\n\n"), SpreadsheetFormat::Csv));

        self::assertSame([1, 3, 7], array_keys($rows));
        self::assertSame(['Imaginary Alps', '1000'], self::texts($rows[7]));
    }

    public function testADelimiterLineIsNotARow(): void
    {
        $rows = $this->rowsOf($this->open($this->file("sep=;\r\nName;Pieces\r\nMade-up Meadow;500\r\n"), SpreadsheetFormat::Csv));

        self::assertSame([1, 2], array_keys($rows));
        self::assertSame(['Name', 'Pieces'], self::texts($rows[1]));
    }

    public function testAFileOfOneColumnIsRead(): void
    {
        $rows = $this->rowsOf($this->open($this->file("Name\nMade-up Meadow\nImaginary Alps; the big one\n"), SpreadsheetFormat::Csv));

        self::assertSame([['Name'], ['Made-up Meadow'], ['Imaginary Alps; the big one']], array_map(self::texts(...), array_values($rows)));
    }

    public function testAnEmptyFileHasNoRows(): void
    {
        self::assertSame([], $this->rowsOf($this->open($this->file(''), SpreadsheetFormat::Csv)));
        self::assertSame([], $this->rowsOf($this->open($this->file("\n\n,,\n"), SpreadsheetFormat::Csv)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function encodings(): iterable
    {
        $czech = ['Žluťoučký kůň', 'Příliš', 'Černý Jiří'];
        $western = ['Hélène', 'Søren', 'São Tomé'];
        $czechFile = "Name;Note;Who\n" . implode(';', $czech) . "\n";
        $westernFile = "Name;Note;Who\n" . implode(';', $western) . "\n";

        yield 'UTF-8' => [$czechFile, $czech];
        yield 'UTF-8 with a mark' => ["\xEF\xBB\xBF" . $czechFile, $czech];
        yield 'Windows-1250' => [(string) iconv('UTF-8', 'Windows-1250', $czechFile), $czech];
        yield 'Windows-1252' => [(string) iconv('UTF-8', 'Windows-1252', $westernFile), $western];
        yield 'UTF-16 little endian with a mark' => ["\xFF\xFE" . mb_convert_encoding($czechFile, 'UTF-16LE', 'UTF-8'), $czech];
        yield 'UTF-16 big endian with a mark' => ["\xFE\xFF" . mb_convert_encoding($czechFile, 'UTF-16BE', 'UTF-8'), $czech];
        yield 'UTF-16 without a mark' => [mb_convert_encoding("Name;Note;Who\nPlain;Text;Here\n", 'UTF-16LE', 'UTF-8'), ['Plain', 'Text', 'Here']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('encodings')]
    public function testTheEncodingIsFound(string $bytes, array $expected): void
    {
        $rows = $this->rowsOf($this->open($this->file($bytes), SpreadsheetFormat::Csv));

        self::assertSame(['Name', 'Note', 'Who'], self::texts($rows[1]));
        self::assertSame($expected, self::texts($rows[2]));
    }

    public function testALargeFileIsLookedThroughInChunksWithoutBreakingACharacter(): void
    {
        // Over a megabyte of two-byte characters, shifted by one byte: a chunk ends in the middle of one
        $line = 'x' . str_repeat('ž', 60) . ';' . str_repeat('ů', 60) . "\n";
        $lines = (int) ceil(1_300_000 / strlen($line));
        $workbook = $this->open($this->file("Name;Note\n" . str_repeat($line, $lines)), SpreadsheetFormat::Csv);
        $count = 0;
        $last = null;

        foreach ($workbook->rows($workbook->sheets()[0]) as $row) {
            $count++;
            $last = $row;
        }

        self::assertSame($lines + 1, $count);
        self::assertSame(['x' . str_repeat('ž', 60), str_repeat('ů', 60)], self::texts($last));
    }

    public function testTheLimitsHoldForTextToo(): void
    {
        $path = $this->file("A,B,C\n1,2,3\n4,5,6\n7,8,9\n");

        self::assertCount(4, $this->rowsOf($this->open($path, SpreadsheetFormat::Csv, new SpreadsheetLimits(maxRowsPerSheet: 4, maxColumns: 3, maxCells: 12))));
        self::assertSame(SpreadsheetUnreadableReason::TooManyRows, $this->reasonWhileStreaming($path, new SpreadsheetLimits(maxRowsPerSheet: 3)));
        self::assertSame(SpreadsheetUnreadableReason::TooManyRows, $this->reasonWhileStreaming($path, new SpreadsheetLimits(maxRows: 3)));
        self::assertSame(SpreadsheetUnreadableReason::TooManyColumns, $this->reasonWhileStreaming($path, new SpreadsheetLimits(maxColumns: 2)));
        self::assertSame(SpreadsheetUnreadableReason::TooManyCells, $this->reasonWhileStreaming($path, new SpreadsheetLimits(maxCells: 11)));

        try {
            $this->open($path, SpreadsheetFormat::Csv, new SpreadsheetLimits(maxFileBytes: 10));
            self::fail('A file over the size limit was opened.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::TooLarge, $e->reason);
        }
    }

    public function testEmptyFieldsCountAsColumns(): void
    {
        $path = $this->file("A,B,,,,,\n1,2,,,,,\n");

        self::assertSame(['1', '2'], self::texts($this->rowsOf($this->open($path, SpreadsheetFormat::Csv, new SpreadsheetLimits(maxColumns: 7)))[2]));
        // A line of nothing but delimiters is as wide as it is long
        self::assertSame(SpreadsheetUnreadableReason::TooManyColumns, $this->reasonWhileStreaming($path, new SpreadsheetLimits(maxColumns: 6)));
        self::assertSame(SpreadsheetUnreadableReason::TooManyColumns, $this->reasonWhileStreaming($this->file("A,B\n" . str_repeat(',', 2_000) . "\n")));
    }

    public function testALineOfFiveMillionDelimitersIsRefusedBeforeItIsParsed(): void
    {
        $path = $this->file("A,B\n1,2\n" . str_repeat(',', 5_000_000) . "\n3,4\n");
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = hrtime(true);

        try {
            $this->open($path, SpreadsheetFormat::Csv);
            self::fail('A line longer than a row can be was accepted.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::CellTooLarge, $e->reason);
        }

        // Five million fields were never made
        self::assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before);
        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);

        // The same in UTF-16 and with old Mac line ends
        foreach (["\xFF\xFE" . mb_convert_encoding("A;B\n" . str_repeat(';', 1_100_000) . "\n", 'UTF-16LE', 'UTF-8'), "A,B\r" . str_repeat(',', 1_100_000) . "\r1,2\r"] as $bytes) {
            try {
                $this->open($this->file($bytes), SpreadsheetFormat::Csv);
                self::fail('A line longer than a row can be was accepted.');
            } catch (SpreadsheetUnreadable $e) {
                self::assertSame(SpreadsheetUnreadableReason::CellTooLarge, $e->reason);
            }
        }
    }

    public function testACellLongerThanACellCanBeIsRefused(): void
    {
        // A quote that never closes takes every line after it into one cell
        $path = $this->file("Name,Notes\nMeadow,\"never closed\n" . str_repeat("another line, and another\n", 10_000));

        self::assertSame(SpreadsheetUnreadableReason::CellTooLarge, $this->reasonWhileStreaming($path));

        // Megabytes after such a quote are never read into one record: the parser sees a window of the file
        $long = $this->file("Name,Notes\nMeadow,\"never closed\n" . str_repeat(str_repeat('another line, and another, ', 30) . "\n", 12_000));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        self::assertGreaterThan(9_000_000, filesize($long));
        self::assertSame(SpreadsheetUnreadableReason::CellTooLarge, $this->reasonWhileStreaming($long));
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);

        // Records over many lines that do close are read across the window's edges, each in one piece
        $multiline = $this->file("Name,Notes\n" . str_repeat("Meadow,\"line one\nline two, with a comma\nline three\"\nAlps,plain\n", 40_000));
        $workbook = $this->open($multiline, SpreadsheetFormat::Csv, new SpreadsheetLimits(maxRows: 100_000, maxRowsPerSheet: 100_000));
        $names = [];

        foreach ($workbook->rows($workbook->sheets()[0]) as $row) {
            $names[$row->cells[1]->asText()] = ($names[$row->cells[1]->asText()] ?? 0) + 1;

            if ($row->cells[1]->asText() === 'Meadow') {
                self::assertSame("line one\nline two, with a comma\nline three", $row->cells[2]->asText());
            }
        }

        self::assertSame(['Name' => 1, 'Meadow' => 40_000, 'Alps' => 40_000], $names);
        self::assertSame(80_001, $workbook->rowsRead());
        self::assertSame(SpreadsheetUnreadableReason::CellTooLarge, $this->reasonWhileStreaming($this->file("A,B\n1," . str_repeat('x', 200) . "\n"), new SpreadsheetLimits(maxCellBytes: 199)));
        self::assertNull($this->reasonWhileStreaming($this->file("A,B\n1," . str_repeat('x', 200) . "\n"), new SpreadsheetLimits(maxCellBytes: 200)));
        // A line may be as long as a row, not longer
        self::assertNull($this->reasonWhileStreaming($this->file("A,B\n" . str_repeat('x', 98) . ",y\n"), new SpreadsheetLimits(maxRowBytes: 100)));

        try {
            $this->open($this->file("A,B\n" . str_repeat('x', 99) . ",y\n"), SpreadsheetFormat::Csv, new SpreadsheetLimits(maxRowBytes: 100));
            self::fail('A line longer than a row can be was accepted.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::CellTooLarge, $e->reason);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textsWithControlCharacters(): iterable
    {
        // Three control characters in a file of honest text (a file of little else is no text at all)
        $line = "Name;Note\nMea\x00dow;be\x07ll\x1B and\ttab\n" . str_repeat("Made-up puzzle;an ordinary note\n", 20);

        yield 'UTF-8' => [$line];
        yield 'Windows-1250' => [(string) iconv('UTF-8', 'Windows-1250', $line . "Žluťoučký;kůň\n")];
        yield 'UTF-16 little endian' => ["\xFF\xFE" . mb_convert_encoding($line, 'UTF-16LE', 'UTF-8')];
        yield 'UTF-16 big endian' => ["\xFE\xFF" . mb_convert_encoding($line, 'UTF-16BE', 'UTF-8')];
    }

    #[DataProvider('textsWithControlCharacters')]
    public function testControlCharactersNeverReachACellWhateverTheEncoding(string $bytes): void
    {
        $rows = $this->rowsOf($this->open($this->file($bytes), SpreadsheetFormat::Csv));

        // A NUL can not even be stored; a tab is text
        self::assertSame(['Meadow', "bell and\ttab"], self::texts($rows[2]));

        foreach ($rows as $row) {
            foreach ($row->cells as $cell) {
                self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $cell->asText());
            }
        }
    }

    public function testATextFileThatIsMostlyControlCharactersIsNoSpreadsheetInUtf16Either(): void
    {
        try {
            $this->open($this->file("\xFF\xFE" . mb_convert_encoding(str_repeat("a\x00\x01\x02;", 200), 'UTF-16LE', 'UTF-8')), SpreadsheetFormat::Csv);
            self::fail('A file of control characters was opened.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::NotASpreadsheet, $e->reason);
        }
    }

    public function testOnlyTheCellsInsideAWindowAreHandedOut(): void
    {
        $workbook = $this->open($this->file("A,B,C,D\n1,2,3,4\n5,6,7,8\n9,10,11,12\n13,14,15,16\n"), SpreadsheetFormat::Csv);
        $rows = iterator_to_array($workbook->rows($workbook->sheets()[0], new CellRange(2, 2, 3, 4)), false);

        self::assertSame([2, 3, 4], array_map(static fn (ReadRow $row): int => $row->number, $rows));
        self::assertSame([2 => '2', 3 => '3'], array_map(static fn ($cell) => $cell->value, $rows[0]->cells));
        self::assertSame(3, $workbook->rowsRead());
        self::assertSame(6, $workbook->cellsRead());
    }

    public function testSeveralWindowsAreReadInOnePass(): void
    {
        $workbook = $this->open($this->file("A,B,C,D\n1,2,3,4\n5,6,7,8\n9,10,11,12\n13,14,15,16\n"), SpreadsheetFormat::Csv);
        $seen = [];

        foreach ($workbook->rows($workbook->sheets()[0], new CellRange(3, 1, 4, 2), new CellRange(1, 2, 2, 4)) as $window => $row) {
            $seen[] = [$window, $row->number, array_map(static fn ($cell) => $cell->value, $row->cells)];
        }

        self::assertSame([
            [0, 1, [3 => 'C', 4 => 'D']],
            [0, 2, [3 => '3', 4 => '4']],
            [1, 2, [1 => '1', 2 => '2']],
            [1, 3, [1 => '5', 2 => '6']],
            [1, 4, [1 => '9', 2 => '10']],
        ], $seen);
    }

    public function testAnOldMacFileWithALineFeedInsideACellIsStillReadByItsCarriageReturns(): void
    {
        // Records end with \r; one cell holds a \n - which is no reason to take the file for a Unix one
        $mac = "Name,Notes\rMeadow,\"line one\nline two\"\rAlps,plain\rHarbour,\"a, b\"\r";
        $rows = $this->rowsOf($this->open($this->file($mac), SpreadsheetFormat::Csv));

        self::assertSame([1, 2, 3, 4], array_keys($rows));
        self::assertSame(['Meadow', "line one\nline two"], self::texts($rows[2]));
        self::assertSame(['Harbour', 'a, b'], self::texts($rows[4]));

        // And the other way round: a Windows file with a bare \r inside a cell is read by its \r\n
        $windows = "Name,Notes\r\nMeadow,\"line one\rline two\"\r\nAlps,plain\r\n";
        $rows = $this->rowsOf($this->open($this->file($windows), SpreadsheetFormat::Csv));

        self::assertSame([1, 2, 3], array_keys($rows));
        self::assertSame(['Meadow', "line one\rline two"], self::texts($rows[2]));

        // A \r\n cut in two by the edge of a chunk is still one line end
        $long = str_repeat('x', 1_048_576 - strlen("Name,Notes\r\n") - 3) . ",y\r\n";
        $rows = $this->rowsOf($this->open($this->file("Name,Notes\r\n" . $long . "Alps,plain\r\n"), SpreadsheetFormat::Csv, new SpreadsheetLimits(maxCellBytes: 2_000_000)));

        self::assertSame([1, 2, 3], array_keys($rows));
        self::assertSame(['Alps', 'plain'], self::texts($rows[3]));
    }

    public function testBytesAndSecondsOfATextFileAreCountedAndAreBudgets(): void
    {
        $path = $this->file("Name,Pieces\n" . str_repeat("Made-up Meadow,500\n", 5_000));
        $size = (int) filesize($path);
        $workbook = $this->open($path, SpreadsheetFormat::Csv);

        // Looked through once when it is opened, read once more when it is streamed
        self::assertSame($size, $workbook->bytesRead());
        self::assertCount(5_001, $this->rowsOf($workbook));
        self::assertGreaterThanOrEqual(2 * $size - 20, $workbook->bytesRead());
        self::assertGreaterThan(0.0, $workbook->secondsRead());
        self::assertLessThan(5.0, $workbook->secondsRead());

        foreach ([[new SpreadsheetLimits(maxReadBytes: $size), SpreadsheetUnreadableReason::TooLarge], [new SpreadsheetLimits(maxReadSeconds: 0.0), SpreadsheetUnreadableReason::TooSlow]] as [$limits, $reason]) {
            try {
                $this->rowsOf($this->open($path, SpreadsheetFormat::Csv, $limits));
                self::fail('A text file was read beyond its budget.');
            } catch (SpreadsheetUnreadable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testAFileClosedWhileItIsStreamedHandsOutNoFurtherRow(): void
    {
        $workbook = $this->open($this->file("\xFF\xFE" . mb_convert_encoding("Name;Pieces\n" . str_repeat("Meadow;500\n", 100), 'UTF-16LE', 'UTF-8')), SpreadsheetFormat::Csv);
        $read = 0;

        for ($sheetRows = $workbook->rows($workbook->sheets()[0]); $sheetRows->valid(); $sheetRows->next()) {
            if (++$read === 10) {
                $workbook->close();
            }
        }

        self::assertSame(10, $read);
        self::assertSame([], glob($this->folder . '/tmp/*'));
    }

    public function testAFolderThatCanNotBeWrittenIsTheServersFaultNotTheFiles(): void
    {
        $utf16 = $this->file("\xFF\xFE" . mb_convert_encoding("Name;Pieces\nMeadow;500\n", 'UTF-16LE', 'UTF-8'));
        // A "folder" under a file: nothing can be made there, whoever runs the test
        $reader = new SpreadsheetReader($this->file('not a folder', 'txt') . '/tmp');

        // A file that needs no rewriting needs no folder
        self::assertCount(1, $reader->open($this->file("Name;Pieces\nMeadow;500\n"), SpreadsheetFormat::Csv)->sheets());

        $this->expectException(SpreadsheetReaderFailed::class);
        $reader->open($utf16, SpreadsheetFormat::Csv);
    }

    /**
     * @return iterable<string, array{string, SpreadsheetUnreadableReason}>
     */
    public static function notText(): iterable
    {
        yield 'a picture' => ["\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . str_repeat("\0\x01\x02", 100), SpreadsheetUnreadableReason::NotASpreadsheet];
        yield 'control characters' => [str_repeat("\x01\x02\x03\x04 text ", 50), SpreadsheetUnreadableReason::NotASpreadsheet];
        yield 'a workbook saved under a text name' => ["PK\x03\x04" . str_repeat('x', 100), SpreadsheetUnreadableReason::UnsupportedFormat];
        yield 'an old workbook' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 100), SpreadsheetUnreadableReason::UnsupportedFormat];
    }

    #[DataProvider('notText')]
    public function testAFileThatIsNotTextIsRefused(string $bytes, SpreadsheetUnreadableReason $reason): void
    {
        try {
            $this->open($this->file($bytes), SpreadsheetFormat::Csv);
            self::fail('A file that is not text was opened.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testAMissingFileIsTheServersFaultNotTheFiles(): void
    {
        $this->expectException(SpreadsheetReaderFailed::class);
        $this->open($this->path('csv'), SpreadsheetFormat::Csv);
    }

    public function testTheRewrittenCopyIsRemovedOnClose(): void
    {
        $workbook = $this->open($this->file("\xFF\xFE" . mb_convert_encoding("Name;Pieces\nMeadow;500\n", 'UTF-16LE', 'UTF-8')), SpreadsheetFormat::Csv);

        // In a folder of its own that only the server reads, under a name nobody can guess
        $folders = (array) glob($this->folder . '/tmp/msp-spreadsheet-*');
        self::assertCount(1, $folders);
        self::assertSame(0700, fileperms((string) $folders[0]) & 0777);
        self::assertCount(1, (array) glob($folders[0] . '/*.csv'));
        self::assertCount(2, $this->rowsOf($workbook));
        // Read again from the start
        self::assertCount(2, $this->rowsOf($workbook));

        $workbook->close();

        self::assertSame([], glob($this->folder . '/tmp/*'));
    }

    public function testTheFormatIsToldFromTheFileName(): void
    {
        self::assertSame(SpreadsheetFormat::Xlsx, SpreadsheetFormat::fromFileName('My puzzles 2024.XLSX'));
        self::assertSame(SpreadsheetFormat::Xlsx, SpreadsheetFormat::fromFileName('with-macros.xlsm'));
        self::assertSame(SpreadsheetFormat::Csv, SpreadsheetFormat::fromFileName('export.csv'));
        self::assertSame(SpreadsheetFormat::Csv, SpreadsheetFormat::fromFileName('export.tsv'));
        self::assertSame(SpreadsheetFormat::Csv, SpreadsheetFormat::fromFileName('export.txt'));
        self::assertNull(SpreadsheetFormat::fromFileName('old.xls'));
        self::assertNull(SpreadsheetFormat::fromFileName('sheet.ods'));
        self::assertNull(SpreadsheetFormat::fromFileName('no-extension'));
    }

    /**
     * @return list<string>
     */
    private static function texts(ReadRow $row): array
    {
        return array_values(array_map(static fn ($cell): string => $cell->asText(), $row->cells));
    }

    private function reasonWhileStreaming(string $path, SpreadsheetLimits $limits = new SpreadsheetLimits()): null|SpreadsheetUnreadableReason
    {
        try {
            $this->rowsOf($this->open($path, SpreadsheetFormat::Csv, $limits));
        } catch (SpreadsheetUnreadable $e) {
            return $e->reason;
        }

        return null;
    }
}
