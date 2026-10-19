<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * The improvement frame of the Insights page (section 15): the pace curve per piece count and, for members, the
 * rating over time.
 */
readonly final class InsightsImprovementFrame
{
    public function __construct(
        public ImprovementCurveResult $curve,
        public null|RatingOverTimeResult $ratingOverTime,
    ) {
    }

    public function firstTriesCount(): int
    {
        return array_sum(array_map(static fn (ImprovementCurveSize $size): int => $size->firstTries, $this->curve->sizes));
    }

    public function jumpsCount(): int
    {
        return array_sum(array_map(static fn (ImprovementCurveSize $size): int => $size->jumpsCount, $this->curve->sizes));
    }
}
