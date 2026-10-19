<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * The rating frame of the Insights page (sections 9 + 10): the opportunities and the forecast, built from one rating
 * model (InsightsFrames::rating()). The forecast only for members - a free player's lock needs only the counts the
 * opportunities carry.
 */
readonly final class InsightsRatingFrame
{
    public function __construct(
        public RatingOpportunitiesResult $opportunities,
        public null|RatingForecastResult $forecast,
    ) {
    }
}
