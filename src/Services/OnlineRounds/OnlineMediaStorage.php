<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use SpeedPuzzling\Web\Value\OnlineMediaObjectList;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PresignedUpload;

/**
 * Evidence objects in object storage (docs/features/online-competitions/README.md §5.11): the phones upload straight to
 * it through presigned URLs - never through PHP - into the media's store (primary, or the second store while the
 * primary is down) under its object key.
 */
interface OnlineMediaStorage
{
    /**
     * The stored object's size and content type (VerifyOnlineMedia) - null when it is not there (yet)
     */
    public function inspect(OnlineEntryMedia $media): null|OnlineMediaObject;

    /**
     * One page of a store's objects under a prefix, in key order, after `$startAfter` (the orphan sweep)
     */
    public function listObjects(OnlineMediaStore $store, string $prefix, null|string $startAfter, int $limit): OnlineMediaObjectList;

    /**
     * Deletes objects of a store by key in one request - a key that is not there is no error
     *
     * @param list<string> $keys at most 1000
     * @return list<string> the keys the store failed to delete
     */
    public function deleteObjects(OnlineMediaStore $store, array $keys): array;

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
