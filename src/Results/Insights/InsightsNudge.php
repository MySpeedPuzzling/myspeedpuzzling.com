<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\InsightsNudgeCode;

/**
 * One "Make your insights sharper" item (InsightsNudgeCode says what count and target mean).
 */
readonly final class InsightsNudge
{
    public function __construct(
        public InsightsNudgeCode $code,
        public null|int $count = null,
        public null|int $target = null,
    ) {
    }
}
