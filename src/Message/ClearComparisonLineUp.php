<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\ComparisonKind;

/**
 * "Clear" on the compare page: throws one line-up of the owner away. Serialized with the adds of the same owner (the same
 * lock key as AddComparisonSubject) - an add racing the clear either lands before it and is cleared, or after it.
 */
readonly final class ClearComparisonLineUp implements SerializedByLock
{
    public function __construct(
        public string $playerId,
        public ComparisonKind $kind,
    ) {
    }

    public function lockKey(): string
    {
        return 'comparison-line-up-' . strtolower($this->playerId);
    }
}
