<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Results\InsightsPuzzle;

/**
 * A built Insights page with the display data of the puzzles it lists (InsightsPageLoader).
 */
readonly final class InsightsPage
{
    /**
     * @param array<string, InsightsPuzzle> $puzzles by lower-case puzzle id - the report's puzzleIdsShown
     */
    public function __construct(
        public InsightsReport $report,
        public array $puzzles,
    ) {
    }
}
