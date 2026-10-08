<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Message\SetPuzzleSlowThreshold;
use SpeedPuzzling\Web\Value\SuspicionDirection;

/**
 * A puzzle whose pending cases of one direction are shown together (docs/features/suspicious-time-review.md, "The
 * piece count is wrong" and "A hard puzzle"): several players raised on it making a fifth of its results, or players
 * are usually far off their level on it.
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
        // A moderator's slow threshold for the current piece count
        public null|float $slowThreshold,
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

    /**
     * @return null|array{float, float} the least and the most a line of the card is off by
     */
    public function ratioRange(): null|array
    {
        $ratios = array_values(array_filter(array_map(
            static fn (SuspiciousTimePuzzleCardCase $case): null|float => $case->ratio(),
            $this->cases,
        )));

        return $ratios === [] ? null : [min($ratios), max($ratios)];
    }

    /**
     * What the threshold form offers: the threshold set, else a whole number a quarter above the slowest line - the
     * moderator decides, this only spares typing.
     */
    public function suggestedSlowThreshold(): float
    {
        if ($this->slowThreshold !== null) {
            return $this->slowThreshold;
        }

        $range = $this->ratioRange();
        $suggested = $range === null ? 10.0 : ceil($range[1] * 1.25);

        return min(SetPuzzleSlowThreshold::MAX, max(SetPuzzleSlowThreshold::MIN, $suggested));
    }
}
