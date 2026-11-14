<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The limits of one import (docs/features/data-import/README.md Q8 and Q9). The file's size is checked before the
 * upload starts; sheets, rows and cells are the reader's to check.
 */
final class DataImportLimits
{
    public const int MAX_FILE_BYTES = 25 * 1024 * 1024;
    public const int MAX_SHEETS = 100;
    public const int MAX_ROWS = 50_000;
    public const int MAX_CELLS = 1_000_000;
    /** Imports whose file arrived and that are neither finished nor discarded */
    public const int MAX_UNFINISHED_PER_PLAYER = 3;
    /** How long the uploaded file is kept in object storage */
    public const int KEEP_FILE_DAYS = 30;
    /** An upload the browser never confirmed is removed after this */
    public const int UNCONFIRMED_UPLOAD_HOURS = 24;
    public const int FILE_NAME_MAX_LENGTH = 200;
}
