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
    /**
     * Longer than a presigned upload URL lives (ImportFileStorage::PUT_LIFETIME_SECONDS): until this much time has
     * passed since the last signature, somebody holding the URL can still put an object under the import's key - so
     * the row that knows the key stays that long (docs/features/data-import/upload.md "A file must not come back").
     */
    public const int SIGNATURE_GRACE_HOURS = 1;
    /** An upload on its way is shown on Imports home once it is this old - then it is stuck, not running */
    public const int UPLOADING_SHOWN_AFTER_SECONDS = 60;
}
