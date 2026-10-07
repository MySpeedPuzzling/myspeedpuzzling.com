<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Asks Query\GetPlayerPaces for the player's pace around one time.
 */
readonly final class PaceRequest
{
    public function __construct(
        // What the answer is keyed by - the time id in the scan
        public string $key,
        public string $playerId,
        // The time itself never counts towards its own pace
        public null|string $excludeTimeId,
        // When it was solved, as a Unix timestamp of the naive timestamp read as UTC (like the SQL reads it)
        public int $solvedAt,
    ) {
    }

    public static function at(string $key, string $playerId, null|string $excludeTimeId, DateTimeImmutable $solvedAt): self
    {
        $asUtc = new DateTimeImmutable($solvedAt->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));

        return new self($key, $playerId, $excludeTimeId, $asUtc->getTimestamp());
    }
}
