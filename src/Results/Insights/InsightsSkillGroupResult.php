<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\InsightsSkillGroup;

/**
 * One group of the skill map with its segments (fastest first). `preview` = a group whose data is still being built
 * (image traits until the picture backfill is complete): shown, but never a finding.
 */
readonly final class InsightsSkillGroupResult
{
    /**
     * @param list<InsightsSkillSegment> $segments
     */
    public function __construct(
        public InsightsSkillGroup $group,
        public array $segments,
        public bool $preview,
    ) {
    }
}
