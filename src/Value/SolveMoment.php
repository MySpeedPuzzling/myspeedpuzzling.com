<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * Where a solve sits in a player's history: `COALESCE(finished_at, tracked_at)` and then `tracked_at`,
 * the order every insights query uses. `finished_at` is a date (midnight) for almost every time, so
 * solves of one day are told apart only by `tracked_at`.
 */
readonly final class SolveMoment
{
    public function __construct(
        public DateTimeImmutable $solvedAt,
        public DateTimeImmutable $trackedAt,
    ) {
    }

    public static function of(null|DateTimeImmutable $finishedAt, DateTimeImmutable $trackedAt): self
    {
        return new self($finishedAt ?? $trackedAt, $trackedAt);
    }
}
