<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The automated completeness check of one finish photo (docs/features/online-competitions/README.md §5.8, Phase 3 -
 * advisory): async, sent once the photo is verified (VerifyOnlineMediaHandler) while the check is on, and by
 * `myspeedpuzzling:online-cv:check-round`. `attempt` counts the tries of a busy or unreachable worker
 * (CheckOnlineFinishPhotoHandler sends the next one itself, later); `force` checks a photo that has a verdict already
 * again (the same worker version replaces its row, a newer one adds its own).
 */
readonly final class CheckOnlineFinishPhoto
{
    public function __construct(
        public string $mediaId,
        public int $attempt = 1,
        public bool $force = false,
    ) {
    }
}
