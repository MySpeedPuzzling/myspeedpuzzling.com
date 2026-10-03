<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * The handler counts the owner's line-up before it adds - two adds of one owner at once (two tabs, a double tap, the
 * entry point and the compare page) would both see room and together go over the cap. The lock covers every line-up
 * of the owner, whichever subject is added.
 */
readonly final class AddComparisonSubject implements SerializedByLock
{
    public function __construct(
        public string $playerId,
        // ComparisonSubjectRef::toString() - "p-<uuid>" or "t-<uuid>"
        public string $subjectRef,
        // At the cap: the row (ComparisonSubject id) of the same line-up that makes room - the "swap"
        public null|string $replaceSubjectId = null,
    ) {
    }

    public function lockKey(): string
    {
        return 'comparison-line-up-' . strtolower($this->playerId);
    }
}
