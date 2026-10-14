<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\OnlineCvAnswer;

/**
 * Stores the CV worker's answer for one finish photo (docs/features/online-competitions/README.md §5.8, Phase 3): a
 * verdict as the row of its worker version, a check that could not run (`answer` failed - never retry) as the row of
 * OnlineMediaCheck::NO_VERSION. The write of CheckOnlineFinishPhotoHandler, which waits for the worker outside any
 * transaction (HandledOutsideTransaction) - not routed: handled at once, in a transaction of its own.
 *
 * Under the photo's lock: two checks of the same photo answering at once (a photo verified twice, check-round next to
 * the upload's own check) write one after the other - the second finds the first's row (the same version: already
 * checked, nothing changes unless forced) instead of breaking the unique (media_id, worker_version).
 */
readonly final class RecordOnlineMediaCheck implements SerializedByLock
{
    public function __construct(
        public string $mediaId,
        public OnlineCvAnswer $answer,
        public int $attempts,
        public bool $force = false,
    ) {
    }

    public function lockKey(): string
    {
        return 'online-media-check-' . strtolower($this->mediaId);
    }
}
