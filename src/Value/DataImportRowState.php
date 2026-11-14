<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where one row of an import stands (docs/features/data-import/README.md "Data model").
 */
enum DataImportRowState: string
{
    case Waiting = 'waiting';
    case Ready = 'ready';
    case Queued = 'queued';
    case Added = 'added';
    case AlreadyYours = 'already_yours';
    case LeftOut = 'left_out';
    case Failed = 'failed';
    case Undone = 'undone';
}
