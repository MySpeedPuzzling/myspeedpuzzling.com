<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\InsightsProfileAxis;

/**
 * One axis of the player's puzzler profile card as the daily batch stored it (player_insights_profile,
 * docs/features/insights/computation.md §6): the raw score, what it rests on and the percentile against every puzzler
 * ("better than X %"). Score and percentile are null while the axis has too few results.
 */
readonly final class InsightsProfileAxisScore
{
    public function __construct(
        public InsightsProfileAxis $axis,
        public null|float $score,
        public int $sampleSize,
        public null|float $percentile,
    ) {
    }
}
