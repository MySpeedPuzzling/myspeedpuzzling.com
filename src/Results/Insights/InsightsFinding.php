<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\InsightsFindingCode;
use SpeedPuzzling\Web\Value\InsightsSection;

/**
 * One takeaway sentence (InsightsFindingCode says which numbers it carries), with the section it jumps to.
 * membersOnly findings are shown to free players as a lock with their count only.
 */
readonly final class InsightsFinding
{
    public function __construct(
        public InsightsFindingCode $code,
        public InsightsSection $section,
        public bool $membersOnly,
        public null|float $value = null,
        public null|int $count = null,
        public null|string $puzzleId = null,
        public null|string $timeId = null,
        public null|InsightsSkillSegment $segment = null,
        public null|int $rank = null,
        public null|int $seconds = null,
    ) {
    }
}
