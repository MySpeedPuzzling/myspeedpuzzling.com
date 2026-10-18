<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RatingPortfolio;

/**
 * One entry of a portfolio strip ("which results make my rating").
 */
readonly final class RatingEntryView
{
    public function __construct(
        public RatingPortfolio $portfolio,
        /** null = a puzzle kept secret now (the entry counts, the puzzle is not shown) */
        public null|InsightsPuzzleRef $puzzle,
        public int $seconds,
        public DateTimeImmutable $solvedAt,
        /** points × 1000 */
        public float $points,
        /** Among the best 100 that count */
        public bool $counts,
        /** When it leaves the 24-month window */
        public DateTimeImmutable $leavesAt,
    ) {
    }
}
