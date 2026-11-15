<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One solo solve as an input of PredictionReconstructor. `solved` / `tracked` are Unix timestamps of
 * COALESCE(finished_at, tracked_at) and tracked_at - the insights order.
 */
final readonly class PredictionInputSolve
{
    public function __construct(
        public string $id,
        public string $puzzleId,
        public int $piecesCount,
        public int $seconds,
        public bool $firstAttempt,
        // Not suspicious, not unboxed - the filter every insights input uses
        public bool $qualifying,
        public float $solved,
        public float $tracked,
        // The stored (shown) prediction when it was a personal one - the repeat residuals of RepeatResiduals
        public null|int $storedPersonalPredictionSeconds = null,
        // The latest moment the solve can have happened (DatePrecision::sqlLatestPossibleMoment()): the end of the
        // month or year for a result that knows no more - null = `solved` itself, a result that knows its day
        public null|float $latestPossible = null,
    ) {
    }

    /**
     * Where the solve stands in "which solve of the puzzle was first": by the latest moment it can have happened, so
     * that a result known to its month or year only is first only when it certainly was
     * (docs/features/data-import/date-precision.md "Which solve was first").
     */
    public function firstOrderMoment(): float
    {
        return $this->latestPossible ?? $this->solved;
    }

    public function isBefore(self $other): bool
    {
        return $this->solved < $other->solved
            || ($this->solved == $other->solved && $this->tracked < $other->tracked);
    }

    public function solvedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . (int) floor($this->solved), new DateTimeZone('UTC'));
    }
}
