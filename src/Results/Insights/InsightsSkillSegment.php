<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\InsightsSkillGroup;

/**
 * One row of the skill map. `key` per group: InsightsSkillGroup. `label` only where the key is no translation code
 * (brand = its name). `drillDownTimeIds` = its results, fastest against your usual first (DRILL_DOWN_LIMIT); `drillDown`
 * = the same results with what the drill-down list shows (time, day, residual) - only the puzzles need a hydrate.
 */
readonly final class InsightsSkillSegment
{
    public const int DRILL_DOWN_LIMIT = 10;

    /**
     * @param list<string> $drillDownTimeIds
     * @param list<InsightsDrillDownResult> $drillDown
     */
    public function __construct(
        public InsightsSkillGroup $group,
        public string $key,
        public null|string $label,
        public InsightsSegmentStats $stats,
        public array $drillDownTimeIds,
        public array $drillDown = [],
    ) {
    }
}
