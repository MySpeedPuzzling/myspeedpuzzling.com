<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where one "Add" - or one Undo - of an import stands (docs/features/data-import/adding.md "The states").
 */
enum DataImportBatchState: string
{
    /** Its rows are queued; the worker goes through them, a few at a time */
    case Running = 'running';
    case Finished = 'finished';
}
