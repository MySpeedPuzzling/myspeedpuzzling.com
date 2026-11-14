<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a row of a sheet stands for (docs/features/data-import/README.md "Data model").
 */
enum DataImportRowMeaning: string
{
    case Result = 'result';
    case Owned = 'owned';
    case Both = 'both';
    case ByColumn = 'by_column';
}
