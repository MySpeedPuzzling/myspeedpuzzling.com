<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Keep it marked" on the "Player replied" tab of the time verification queue (docs/features/suspicious-time-review.md,
 * "Moderator queue"): the player's "The time is correct" is answered with the moderator's note, the time stays marked.
 */
readonly final class KeepSolvingTimeSuspicious implements SerializedByLock
{
    /**
     * @param string $seenFingerprint the entry the moderator looked at (SuspicionFingerprint) - refused when it changed
     */
    public function __construct(
        public string $caseId,
        public string $decidedById,
        public string $note,
        public string $seenFingerprint,
    ) {
    }

    /**
     * Two moderators deciding about one case at once must not both see it undecided.
     */
    public function lockKey(): string
    {
        return 'suspicious-time-case-' . strtolower($this->caseId);
    }
}
