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
    /**
     * What an import's rows may hold as text altogether (the reader bounds a cell and a row - 50,000 rows of 1 MiB
     * each would still be 50 GB): twice what the largest honest import stores (README "Large imports": 50-150 MB).
     */
    public const int MAX_STORED_BYTES = 300 * 1024 * 1024;
    /** Rows written per transaction while a file is read */
    public const int ROWS_PER_BATCH = 500;
    /** … or fewer, when they are heavy: a batch travels as one message */
    public const int BYTES_PER_BATCH = 4 * 1024 * 1024;
    /** CPU seconds the reader gets for one file (docs/features/data-import/reader.md) */
    public const float READ_CPU_SECONDS = 60.0;
    /** … and seconds on the wall clock, the last resort */
    public const float READ_WALL_SECONDS = 300.0;
    /** PHP's own time limit around a read - a parser that hangs is killed with the worker, the import is rescued */
    public const int READ_HARD_SECONDS = 420;
    /** An uploaded import nobody started to read after this long is queued again (a message that was lost) */
    public const int UNREAD_AFTER_SECONDS = 120;
    /** … and once a read was asked for, it is asked for again only after this long */
    public const int READ_REQUESTED_AGAIN_AFTER_SECONDS = 900;
    /** Downloading the file from object storage: no answer for this long, or this long in all, is a fault of ours */
    public const int DOWNLOAD_IDLE_SECONDS = 20;
    public const float DOWNLOAD_TOTAL_SECONDS = 120.0;
    /** An import "being read" without any progress for this long: its worker died */
    public const int READING_STALLED_AFTER_SECONDS = 900;
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
