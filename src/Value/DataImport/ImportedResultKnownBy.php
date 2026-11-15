<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How ImportedResultWriter knew a row's result exists already.
 */
enum ImportedResultKnownBy: string
{
    // This very row made it (a second delivery, a retry)
    case Row = 'row';
    // Another row of the player with the same source key made it (the same data in another file or sheet)
    case SourceKey = 'source_key';
}
