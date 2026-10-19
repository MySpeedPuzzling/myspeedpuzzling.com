<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * A pair/team result against what the members' solo paces predict (PairsTeamsSynergy).
 */
readonly final class GroupResultScore
{
    public function __construct(
        public InsightsGroupResult $result,
        /** c(size, first try / repeat) × difficulty ÷ Σ 1/paceᵢ, every pace as of the solve */
        public int $expectedSeconds,
        /** actual ÷ expected: 0.9 = 10 % faster than expected */
        public float $ratio,
    ) {
    }
}
