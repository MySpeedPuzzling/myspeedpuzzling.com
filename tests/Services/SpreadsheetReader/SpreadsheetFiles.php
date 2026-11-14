<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Services\SpreadsheetReader\ReadWorkbook;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * Temporary files of a reader test: a folder of the test's own (parallel test processes share /tmp), removed after
 * it - with the proof that the reader left nothing behind in the temporary folder it was given.
 */
trait SpreadsheetFiles
{
    private string $folder = '';
    /** @var list<ReadWorkbook> */
    private array $workbooks = [];

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/reader-test-' . bin2hex(random_bytes(6));
        mkdir($this->folder . '/tmp', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeFiles();
    }

    private function removeFiles(): void
    {
        foreach ($this->workbooks as $workbook) {
            $workbook->close();
        }

        $this->workbooks = [];
        $leftBehind = glob($this->folder . '/tmp/*');

        foreach (glob($this->folder . '/*.*') ?: [] as $file) {
            @unlink($file);
        }

        foreach ($leftBehind ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }

        @rmdir($this->folder . '/tmp');
        @rmdir($this->folder);

        self::assertSame([], $leftBehind, 'The reader left something in its temporary folder.');
    }

    private function reader(): SpreadsheetReader
    {
        return new SpreadsheetReader($this->folder . '/tmp');
    }

    private function path(string $extension = 'xlsx'): string
    {
        return $this->folder . '/' . bin2hex(random_bytes(6)) . '.' . $extension;
    }

    private function file(string $content, string $extension = 'csv'): string
    {
        $path = $this->path($extension);
        file_put_contents($path, $content);

        return $path;
    }

    private function saved(RawXlsx $workbook): string
    {
        $path = $this->path();
        $workbook->save($path);

        return $path;
    }

    private function open(string $path, SpreadsheetFormat $format = SpreadsheetFormat::Xlsx, SpreadsheetLimits $limits = new SpreadsheetLimits()): ReadWorkbook
    {
        return $this->workbooks[] = $this->reader()->open($path, $format, $limits);
    }

    /**
     * @return array<int, ReadRow> row number => row, of a sheet (1 = the first)
     */
    private function rowsOf(ReadWorkbook $workbook, int $position = 1): array
    {
        $rows = [];

        foreach ($workbook->rows($workbook->sheets()[$position - 1]) as $row) {
            $rows[$row->number] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, ReadRow> $rows
     */
    private static function cell(array $rows, int $row, int $column): ReadCell
    {
        $cell = ($rows[$row] ?? null)?->cell($column);
        self::assertNotNull($cell, sprintf('No value in row %d, column %d.', $row, $column));

        return $cell;
    }
}
