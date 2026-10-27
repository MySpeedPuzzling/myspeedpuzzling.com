<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One stored prediction as the evaluations read it (solving_time_prediction): the predicted seconds and the ends of its
 * 80 % and 50 % windows. Whatever predictor or model version wrote it.
 */
readonly final class StoredPredictionWindows
{
    public function __construct(
        public int $predictedSeconds,
        public int $range80Low,
        public int $range50Low,
        public int $range50High,
        public int $range80High,
    ) {
    }

    /**
     * Null when one of the numbers is missing (a predictor that had nothing for the time)
     */
    public static function tryOf(null|int $predicted, null|int $range80Low, null|int $range50Low, null|int $range50High, null|int $range80High): null|self
    {
        if ($predicted === null || $range80Low === null || $range50Low === null || $range50High === null || $range80High === null) {
            return null;
        }

        return new self($predicted, $range80Low, $range50Low, $range50High, $range80High);
    }

    /** |ln(actual ÷ predicted)| */
    public function error(int $actualSeconds): float
    {
        return abs(log(max(1, $actualSeconds) / max(1, $this->predictedSeconds)));
    }

    /**
     * The window's log width (ln high − ln low) at one level
     */
    public function logWidth(int $level): float
    {
        [$low, $high] = $this->ends($level);

        return log(max(1, $high)) - log(max(1, $low));
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function ends(int $level): array
    {
        return $level === 50 ? [$this->range50Low, $this->range50High] : [$this->range80Low, $this->range80High];
    }
}
