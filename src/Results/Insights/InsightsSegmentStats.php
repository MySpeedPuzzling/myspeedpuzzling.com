<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\InsightsVerdict;

/**
 * How a group of the player's results compares with their usual: the shrunk median of their residuals with its
 * order-statistic range (docs/features/insights/computation.md "Core sections" §6). Fractions: -0.05 = 5 % faster
 * than your usual, 0.03 = 3 % slower. Null while fewer than SegmentEffect::MIN_SHOWN results.
 */
readonly final class InsightsSegmentStats
{
    public function __construct(
        public int $sampleSize,
        public null|float $effect,
        public null|float $low,
        public null|float $high,
        public InsightsVerdict $verdict,
    ) {
    }
}
