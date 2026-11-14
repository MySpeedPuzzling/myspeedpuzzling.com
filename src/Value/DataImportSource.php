<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an import's spreadsheet came from (docs/features/data-import/README.md "Data model").
 */
enum DataImportSource: string
{
    case File = 'file';
    case GoogleSheet = 'google_sheet';
}
