<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Sends a round's verified finish photos to the completeness check (`myspeedpuzzling:online-cv:check-round`, Phase 3):
 * those without a verdict yet - every one with `force` (a newer worker version, the thresholds changed). Handled at
 * once; answers how many photos were sent (CheckOnlineFinishPhoto each, async).
 */
readonly final class CheckOnlineRoundFinishPhotos
{
    public function __construct(
        public string $roundId,
        public bool $force = false,
    ) {
    }
}
