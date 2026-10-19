<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "Where are my strengths and weak spots?" (members; free players see the first strength and weak spot as findings).
 * strengths / weakSpots: every segment with that verdict outside preview groups, clearest first.
 */
readonly final class InsightsSkillMapSection
{
    /**
     * @param list<InsightsSkillGroupResult> $groups
     * @param list<InsightsSkillSegment> $strengths
     * @param list<InsightsSkillSegment> $weakSpots
     */
    public function __construct(
        public array $groups,
        public array $strengths,
        public array $weakSpots,
        public int $sampleSize,
        public bool $notSureYet,
    ) {
    }
}
