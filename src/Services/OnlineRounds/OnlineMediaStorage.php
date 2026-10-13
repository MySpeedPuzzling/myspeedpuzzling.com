<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Value\PresignedUpload;

/**
 * Evidence objects in object storage (docs/features/online-competitions/README.md §5.11): the phones upload straight to
 * it through presigned URLs - never through PHP - into the media's store (primary, or the second store while the
 * primary is down) under its object key.
 */
interface OnlineMediaStorage
{
    /**
     * A presigned `PUT` for the media's store and key, signed for its content type and declared length
     */
    public function presignPut(OnlineEntryMedia $media): PresignedUpload;

    /**
     * A short-lived URL to look at the object (control room thumbnails, the review)
     */
    public function presignGet(OnlineEntryMedia $media, int $ttlSeconds): string;

    /**
     * The object's size in bytes - null when it is not there (yet)
     */
    public function head(OnlineEntryMedia $media): null|int;

    public function delete(OnlineEntryMedia $media): void;
}
