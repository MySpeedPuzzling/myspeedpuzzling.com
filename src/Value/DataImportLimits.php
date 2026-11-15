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
    /**
     * Fetching a Google sheet's export (docs/features/data-import/google-sheets.md): seconds to connect, seconds
     * without a byte, seconds in all - over every redirect together
     */
    public const float GOOGLE_CONNECT_SECONDS = 5.0;
    public const float GOOGLE_IDLE_SECONDS = 20.0;
    public const float GOOGLE_TOTAL_SECONDS = 60.0;
    public const int GOOGLE_MAX_REDIRECTS = 5;
    /** An import "being fetched" - waiting for the worker or with it - without any news for this long ends as failed */
    public const int FETCHING_STALLED_AFTER_SECONDS = 900;
    /** The widest sheet the reader hands over (its own sanity cap) - a column number beyond it is no column */
    public const int MAX_COLUMNS = 1000;
    /** A mapped table is at most this wide (README Q8); a wider one is cut on its right */
    public const int MAX_REGION_COLUMNS = 60;
    /** Rows from a sheet's top that the header, the tables and the columns are recognised from (columns.md) */
    public const int SAMPLE_ROWS = 300;
    /** Rows of a table whose times and dates settle the column's open questions - what they leave open is asked */
    public const int COLUMN_DECISION_ROWS = 5000;
    /** Rows of a sheet that are equal in everything a key holds and are numbered against each other */
    public const int MAX_EQUAL_ROWS_PER_SHEET = 5000;
    /** A read import's lost or failed step (its mapping, a sheet's parse) is queued again for this long after the read */
    public const int SET_UP_RETRIED_FOR_DAYS = 7;
    /** A sheet's parse that gave no news for this long is taken to be dead: another job may take it over */
    public const int PARSE_CLAIM_SECONDS = 900;
    /** How often a sheet's step that runs by itself (its proposal, its parse) is started before the sheet is failed */
    public const int MAX_SET_UP_ATTEMPTS = 3;
    /** Keys shared by several rows of a sheet that are numbered in one go (whole groups, never a cut one) */
    public const int EQUAL_KEYS_PER_PAGE = 200;
    /** Names a puzzle list is looked up by */
    public const int MAX_LOOKUP_NAMES = 50_000;

    /**
     * Imports in progress per player: neither finished nor discarded - which of them count is
     * Services\DataImport\ImportSlots' to say
     */
    public const int MAX_UNFINISHED_PER_PLAYER = 3;
    /**
     * An import that added rows, has none ready and only waits for the player is finished by the daily sweep after
     * so many days without anything happening to it (upload.md "Finished")
     */
    public const int FINISHED_AFTER_WAITING_DAYS = 7;
    /**
     * … and an import with nothing left at all after so many hours of rest: "Go on with this import" is not undone
     * by the next night's sweep
     */
    public const int FINISHED_AFTER_SETTLED_HOURS = 24;
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

    /** Results one player's imports add per day, UTC (README Q4) - the rest is offered tomorrow */
    public const int MAX_RESULTS_ADDED_PER_DAY = 2000;
    /**
     * Registered players - other than the importing one - that one "Add" puts results on at most
     * (docs/features/data-import/people.md "Limits"); a row naming one more waits with the reason
     */
    public const int MAX_PARTNERS_PER_BATCH = 20;
    /** Results one player's imports put on one registered partner per day, UTC - the rest waits: nobody is flooded */
    public const int MAX_RESULTS_PER_PARTNER_PER_DAY = 200;
    /** Rows of an "Add" or an Undo the worker goes through before it gives the queue to the next job */
    public const int ROWS_PER_TURN = 50;
    /** ... or after so many seconds (README "no message works longer than about ten seconds") */
    public const float SECONDS_PER_TURN = 8.0;
    /** A running "Add" or Undo nobody worked on for so long is continued by the sweeper */
    public const int BATCH_STANDING_AFTER_SECONDS = 300;
}
