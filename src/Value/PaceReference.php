<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The community's pace for one piece-count range and puzzling type (suspicious_time_reference): what makes piece
 * counts comparable for the pace fallback, the bar for a player without times of their own, and the slow floor of
 * pair/team results (docs/features/suspicious-time-review.md, "The expected time").
 */
readonly final class PaceReference
{
    public function __construct(
        public SuspicionPiecesRange $piecesRange,
        public PuzzlingType $puzzlingType,
        public float $medianPpm,
        public float $p999Ppm,
        public int $sampleSize,
    ) {
    }

    /**
     * How fast one result was compared to the community median (1.0 = the median).
     */
    public function relativePace(int $piecesCount, int $seconds): float
    {
        return ($piecesCount * 60 / $seconds) / $this->medianPpm;
    }

    /**
     * The seconds somebody with this relative pace takes for this piece count.
     */
    public function secondsAt(int $piecesCount, float $relativePace): int
    {
        return (int) round($piecesCount * 60 / ($relativePace * $this->medianPpm));
    }

    /**
     * The community median time for this piece count.
     */
    public function medianSeconds(int $piecesCount): int
    {
        return $this->secondsAt($piecesCount, 1.0);
    }
}
