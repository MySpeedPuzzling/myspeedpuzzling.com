<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "How fast do I learn a puzzle?" (members): your curve from player_improvement_ratio (all your puzzles, the batch's
 * numbers), the community's from global_improvement_ratio for communityPiecesCount, and your curve within the
 * selection (form taken out), plus the puzzles you improved on most.
 */
readonly final class InsightsLearningSection
{
    public const int LIST_LIMIT = 6;

    /**
     * @param list<InsightsLearningPoint> $you attempts 1-4
     * @param list<InsightsLearningPoint> $community attempts 1-4
     * @param list<InsightsLearningPoint> $inSelection attempts 1-4
     * @param list<InsightsLearnedPuzzle> $learnedMost
     */
    public function __construct(
        public array $you,
        public array $community,
        public int $communityPiecesCount,
        public array $inSelection,
        public array $learnedMost,
        public int $repeatedPuzzleCount,
        public bool $notSureYet,
    ) {
    }
}
