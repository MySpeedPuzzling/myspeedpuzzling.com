<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\Spreadsheet\CellKind;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * Reading streams: the memory a sheet needs does not grow with its rows (docs/features/data-import/README.md "Large
 * imports"). The workbooks are generated here - ten kinds of cells per row, every row with a text of its own, so the
 * shared strings go to the reader's file.
 *
 * The largest allowed file (50,000 rows × 20 columns) is read by the opt-in test at the end:
 * SPREADSHEET_READER_LARGE=1 vendor/bin/phpunit --group spreadsheet-reader-large tests/Services/SpreadsheetReader
 */
final class SpreadsheetReaderMemoryTest extends TestCase
{
    use SpreadsheetFiles;

    private const int MEBIBYTE = 1024 * 1024;

    public function testMemoryDoesNotGrowWithTheRowsOfASheet(): void
    {
        $small = $this->generatedWorkbook(2_000, 10);
        $large = $this->generatedWorkbook(20_000, 10);

        // Classes are loaded by the first read, not by the rows
        $this->streamed($small);

        [$smallCells, $smallGrowth] = $this->streamed($small);
        [$largeCells, $largeGrowth] = $this->streamed($large);

        self::assertSame(20_000, $smallCells['rows']);
        self::assertSame(200_000, $largeCells['rows']);
        self::assertSame(
            [
                'rows' => 200_000,
                CellKind::Text->value => 60_000,
                CellKind::Number->value => 60_000,
                CellKind::Date->value => 40_000,
                CellKind::Duration->value => 20_000,
                CellKind::Boolean->value => 20_000,
            ],
            $largeCells,
        );

        self::assertLessThan(4 * self::MEBIBYTE, $largeGrowth, sprintf('Reading 200,000 cells took %.1f MiB.', $largeGrowth / self::MEBIBYTE));
        // Ten times the rows, the same memory (a megabyte of slack for PHP's own allocator)
        self::assertLessThan($smallGrowth + self::MEBIBYTE, $largeGrowth, sprintf('2,000 rows took %.1f MiB, 20,000 rows %.1f MiB.', $smallGrowth / self::MEBIBYTE, $largeGrowth / self::MEBIBYTE));
    }

    public function testMemoryDoesNotGrowWithTheRowsOfATextFile(): void
    {
        $line = static fn (int $row): string => sprintf("%d,Made-up puzzle %d,Invented brand %d,%d,0:%02d:%02d,05.03.2024,\"note, %d\"\n", $row, $row, $row % 50, 500 + $row % 7 * 500, $row % 60, $row % 59, $row);
        $path = $this->path('csv');
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fwrite($handle, "ID,Name,Brand,Pieces,Time,Date,Notes\n");

        for ($row = 1; $row <= 40_000; $row++) {
            fwrite($handle, $line($row));
        }

        fclose($handle);
        $this->open($this->file("a,b\n1,2\n"), SpreadsheetFormat::Csv)->close();

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $workbook = $this->open($path, SpreadsheetFormat::Csv);
        $rows = 0;

        foreach ($workbook->rows($workbook->sheets()[0]) as $row) {
            $rows++;
        }

        $growth = memory_get_peak_usage() - $before;

        self::assertSame(40_001, $rows);
        self::assertLessThan(4 * self::MEBIBYTE, $growth, sprintf('Reading 280,000 text cells took %.1f MiB.', $growth / self::MEBIBYTE));
    }

    #[Group('spreadsheet-reader-large')]
    public function testTheLargestAllowedFileIsReadWithinItsBudget(): void
    {
        if (getenv('SPREADSHEET_READER_LARGE') !== '1') {
            self::markTestSkipped('Opt-in: SPREADSHEET_READER_LARGE=1 (a million cells take about half a minute).');
        }

        $path = $this->generatedWorkbook(50_000, 20);
        $this->streamed($this->generatedWorkbook(10, 10));

        $started = hrtime(true);
        [$cells, $growth] = $this->streamed($path);
        $seconds = (hrtime(true) - $started) / 1e9;

        fwrite(STDERR, sprintf(
            "\n50,000 rows × 20 columns (%d cells, file %.1f MiB): %.1f s, peak memory growth %.1f MiB, PHP %s\n",
            $cells['rows'],
            filesize($path) / self::MEBIBYTE,
            $seconds,
            $growth / self::MEBIBYTE,
            PHP_VERSION,
        ));

        self::assertSame(1_000_000, $cells['rows']);
        self::assertLessThan(8 * self::MEBIBYTE, $growth);
        self::assertLessThan(60.0, $seconds);
    }

    /**
     * @return array{array<string, int>, int} cells in all ('rows' - one per cell here) and per kind; peak memory growth in bytes
     */
    private function streamed(string $path): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $workbook = $this->open($path, limits: new SpreadsheetLimits(maxRows: 50_000));
        $counts = ['rows' => 0];

        foreach ($workbook->rows($workbook->sheets()[0]) as $row) {
            foreach ($row->cells as $cell) {
                $counts['rows']++;
                $counts[$cell->kind->value] = ($counts[$cell->kind->value] ?? 0) + 1;
            }
        }

        $growth = memory_get_peak_usage() - $before;
        $workbook->close();
        array_pop($this->workbooks);

        return [$counts, $growth];
    }

    private function generatedWorkbook(int $rows, int $columns): string
    {
        $path = $this->path();
        RawXlsx::generate($path, $rows, $columns);

        return $path;
    }
}
