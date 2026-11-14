<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\CellKind;
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
        self::assertNotNull($last);
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

    public function testEmptyCellsBeyondTheLastAllowedColumnAreNoValues(): void
    {
        $rows = $this->rowsOf($this->open($this->file("A,B,,,,,\n1,2,,,,,\n"), SpreadsheetFormat::Csv, new SpreadsheetLimits(maxColumns: 2)));

        self::assertSame(['1', '2'], self::texts($rows[2]));
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

    public function testAMissingFileIsDamaged(): void
    {
        try {
            $this->open($this->path('csv'), SpreadsheetFormat::Csv);
            self::fail('A file that is not there was opened.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason);
        }
    }

    public function testTheRewrittenCopyIsRemovedOnClose(): void
    {
        $workbook = $this->open($this->file("\xFF\xFE" . mb_convert_encoding("Name;Pieces\nMeadow;500\n", 'UTF-16LE', 'UTF-8')), SpreadsheetFormat::Csv);

        self::assertCount(1, (array) glob($this->folder . '/tmp/msp-spreadsheet-*.csv'));
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

    private function reasonWhileStreaming(string $path, SpreadsheetLimits $limits): null|SpreadsheetUnreadableReason
    {
        try {
            $this->rowsOf($this->open($path, SpreadsheetFormat::Csv, $limits));
        } catch (SpreadsheetUnreadable $e) {
            return $e->reason;
        }

        return null;
    }
}
