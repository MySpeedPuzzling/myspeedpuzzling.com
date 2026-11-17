<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * One earlier attempt of a studio member on a round puzzle (GetAnalyticsStudioEntrantHistory): solo or as a member of
 * a pair/team, finished (seconds), unfinished (pieces placed - never a time) or relaxed (neither).
 */
readonly final class AnalyticsStudioAttempt
{
    public function __construct(
        public string $puzzleId,
        public string $memberId,
        // solo | duo | team
        public string $kind,
        public null|string $puzzlingTeamId,
        public null|int $seconds,
        public null|int $piecesPlaced,
        public bool $firstAttempt,
        public bool $unboxed,
        public DateTimeImmutable $solvedAt,
        public DatePrecision $precision,
    ) {
    }

    public function countsAsSolve(): bool
    {
        // CountsAsSolve::condition(): an unfinished result is no solve
        return $this->piecesPlaced === null;
    }

    public function isGroup(): bool
    {
        return $this->kind !== 'solo';
    }
}
