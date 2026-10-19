<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * The top row's lazy finding cards (InsightsFrames::top()): "Try next" from the shelf picks and "MSP rating" from the
 * rating opportunities - the same cache entries the shelf and rating frames read.
 */
readonly final class InsightsTopFrame
{
    public function __construct(
        public RatingOpportunitiesResult $rating,
        public ShelfPicksResult $shelf,
    ) {
    }
}
