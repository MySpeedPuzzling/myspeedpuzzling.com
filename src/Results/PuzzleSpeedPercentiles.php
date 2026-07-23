<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Value\SpeedPercentile;

/**
 * Solo-timed time distribution of one puzzle — the reference for the XP speed bonus.
 * The bonus requires a median backed by at least 3 distinct solvers (§1.8 anti-abuse).
 */
readonly final class PuzzleSpeedPercentiles
{
    public function __construct(
        public int $distinctSolvers,
        public null|float $medianSeconds,
        public null|float $p25Seconds,
        public null|float $p10Seconds,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, null, null, null);
    }

    public function hasReliableMedian(): bool
    {
        return $this->medianSeconds !== null
            && $this->distinctSolvers >= XpCalculator::SPEED_BONUS_MIN_DISTINCT_SOLVERS;
    }

    /**
     * Lower seconds = faster: top-10% means being at or below the 10th percentile time.
     */
    public function percentileFor(int $secondsToSolve): SpeedPercentile
    {
        if ($this->hasReliableMedian() === false || $this->medianSeconds === null) {
            return SpeedPercentile::None;
        }

        if ($this->p10Seconds !== null && $secondsToSolve <= $this->p10Seconds) {
            return SpeedPercentile::Top10;
        }

        if ($this->p25Seconds !== null && $secondsToSolve <= $this->p25Seconds) {
            return SpeedPercentile::Top25;
        }

        if ($secondsToSolve < $this->medianSeconds) {
            return SpeedPercentile::AboveMedian;
        }

        return SpeedPercentile::None;
    }
}
