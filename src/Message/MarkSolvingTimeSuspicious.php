<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Needs verification" on a pending case of the time verification queue (docs/features/suspicious-time-review.md,
 * "Moderator queue"): the time is flagged and the player is told the ticked reasons with the moderator's note.
 */
readonly final class MarkSolvingTimeSuspicious implements SerializedByLock
{
    /**
     * @param list<string> $reasonCodes the reasons the player reads (SuspiciousTimeReasonCode values) - only the
     *                                  case's own reasons a player may read count
     * @param string $seenFingerprint the entry the moderator looked at (SuspicionFingerprint) - refused when it changed
     */
    public function __construct(
        public string $caseId,
        public string $decidedById,
        public array $reasonCodes,
        public null|string $note,
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
