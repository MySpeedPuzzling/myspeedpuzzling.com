<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one personal link may put into the database and object storage (docs/features/online-competitions/
 * contracts/phase-1.md §6) - every link is treated as a possibly hostile client. A 90-minute round sends a few dozen
 * events, ~200 photos (35 KB periodic ones, every 30 s) and a few finish bursts of 1-2 MB, so the caps are far above
 * any real phone and far below what would hurt.
 */
final readonly class OnlineEntryQuota
{
    // Events of one online entry (a participant in one round) - beyond: refused `too_many_events`
    public const int MAX_EVENTS_PER_ENTRY = 5_000;
    // One event's `data` as JSON - a `media.uploaded` event's manifest is counted apart (MAX_MANIFEST_FRAMES)
    public const int MAX_EVENT_DATA_BYTES = 2_048;
    // A finish burst is ±5 s at ~10 fps
    public const int MAX_MANIFEST_FRAMES = 300;

    // One evidence object - the presigned upload is signed for exactly the declared size
    public const int MAX_MEDIA_BYTES = 15 * 1024 * 1024;
    // Media rows of one online entry, and the bytes they declared - beyond: refused `media_quota_exceeded`
    public const int MAX_MEDIA_PER_ENTRY = 1_500;
    public const int MAX_MEDIA_BYTES_PER_ENTRY = 500 * 1024 * 1024;
    // Presigned uploads not verified yet within PENDING_MEDIA_WINDOW_SECONDS - beyond: `too_many_pending_media` (429,
    // the phone comes back later; a store that is down makes every phone ask again and again)
    public const int MAX_PENDING_MEDIA_PER_ENTRY = 100;
    public const int PENDING_MEDIA_WINDOW_SECONDS = 1_800;
    public const int PENDING_MEDIA_RETRY_AFTER_SECONDS = 60;
}
