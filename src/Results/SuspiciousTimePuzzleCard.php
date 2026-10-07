<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SuspicionDirection;

/**
 * A puzzle whose pending cases of one direction are shown together (docs/features/suspicious-time-review.md, "The
 * piece count is wrong"): several players raised on it making a fifth of its results, or players are usually far off
 * their level on it.
 */
readonly final class SuspiciousTimePuzzleCard
{
    /**
     * @param list<SuspiciousTimePuzzleCardCase> $cases
     */
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public null|float $difficultyScore,
        public int $playersCount,
        public int $soloResults,
        public null|int $medianSolo,
        public null|int $fastestSolo,
        public SuspicionDirection $direction,
        public array $cases,
    ) {
    }

    public function isSeveralPlayers(): bool
    {
        return $this->playersCount >= 2;
    }
}
