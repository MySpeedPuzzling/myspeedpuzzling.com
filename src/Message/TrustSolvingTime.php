<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

/**
 * "Looks fine" in the time verification queue (docs/features/suspicious-time-review.md, "Moderator queue"): on a
 * pending case the entry is trusted, on a marked one it is also unmarked - and an open "The time is correct" of the
 * player is answered "Your time counts again".
 */
readonly final class TrustSolvingTime implements SerializedByLock
{
    /**
     * @param string $seenFingerprint the entry the moderator looked at (SuspicionFingerprint) - refused when it changed
     * @param SuspiciousTimeCaseStatus $seenStatus pending or marked, as the page showed it - refused when it changed
     */
    public function __construct(
        public string $caseId,
        public string $decidedById,
        public null|string $note,
        public string $seenFingerprint,
        public SuspiciousTimeCaseStatus $seenStatus,
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
