<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What `myspeedpuzzling:backfill-photo-stash-entries` found (BackfillPhotoStashEntriesHandler)
 */
final class PhotoStashBackfillResult
{
    public int $descriptions = 0;
    // Copies of rows written by the new release for the previous one
    public int $mirrors = 0;
    public int $expired = 0;
    public int $broken = 0;
    public int $alreadyRecorded = 0;
    // Still valid, without a row: recorded with `write`, else what a write would record
    public int $toRecord = 0;
    public int $recorded = 0;
    // The player is gone, or the row could not be written (logged)
    public int $failed = 0;
}
