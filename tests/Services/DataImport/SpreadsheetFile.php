<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use SpeedPuzzling\Web\Services\DataImport\ImportRowCells;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetHints;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * A real file's bytes the way the import stores them: read by SpreadsheetReader, kept as ImportRowCells keeps a
 * row - the first sheet, as a SheetSample.
 */
final class SpreadsheetFile
{
    public function read(string $content, SpreadsheetFormat $format): SheetSample
    {
        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);
        file_put_contents($path, $content);
        $workbook = null;

        try {
            $workbook = (new SpreadsheetReader())->open($path, $format, new SpreadsheetLimits());
            $sheet = $workbook->sheets()[0];
            $rows = [];

            foreach ($workbook->rows($sheet) as $row) {
                $rows[$row->number] = ImportRowCells::of($row);
            }

            // Through JSON, as the database gives them back
            /** @var array<int, array<int, array<string, mixed>>> $stored */
            $stored = json_decode(json_encode($rows, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

            return new SheetSample($sheet->title, $stored, ImportSheetHints::of($sheet), count($stored));
        } finally {
            $workbook?->close();
            unlink($path);
        }
    }
}
