<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Looks fine" on a time, without the queue's card (docs/features/suspicious-time-review.md, "Internal API") - the
 * internal API's unmark-suspicious. A flagged time is unmarked (a flag set by SQL the scan has not reconciled yet
 * too), a pending case is trusted; a time neither flagged nor pending is refused (SolvingTimeNotSuspicious).
 */
readonly final class UnmarkSolvingTimeSuspiciousDirectly implements SerializedByLock
{
    public function __construct(
        public string $timeId,
        public string $decidedById,
        public null|string $note,
    ) {
    }

    public function lockKey(): string
    {
        return 'suspicious-time-of-' . strtolower($this->timeId);
    }
}
