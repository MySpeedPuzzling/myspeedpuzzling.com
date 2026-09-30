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
        // Today's baseline of the solver for the piece count - only on other players' solves
        public null|int $solverBaseline = null,
    ) {
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
