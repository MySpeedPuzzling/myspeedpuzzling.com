<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Needs verification" on a time, without the queue's card (docs/features/suspicious-time-review.md, "Internal
 * API") - the internal API's mark-suspicious. Whatever case the time has (a pending one, a trusted one, none):
 * the time is flagged and marked, decided by $decidedById.
 */
readonly final class MarkSolvingTimeSuspiciousDirectly implements SerializedByLock
{
    /**
     * @param null|list<string> $reasonCodes the reasons the player reads (SuspiciousTimeReasonCode values) - only the
     *                                       case's own reasons a player may read, and only while the scan's reasons
     *                                       are about this very entry; null = all of them
     * @param bool $toldByHand the player was (or will be) e-mailed by hand: the notices are recorded as sent
     *                         (manual_email), so the banner and the "Your results" e-mail never repeat it
     */
    public function __construct(
        public string $timeId,
        public string $decidedById,
        public null|string $note,
        public null|array $reasonCodes,
        public bool $toldByHand,
    ) {
    }

    public function lockKey(): string
    {
        return 'suspicious-time-of-' . strtolower($this->timeId);
    }
}
