<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\RatingForecastScenario;

readonly final class RatingForecastLine
{
    /**
     * @param list<RatingForecastPoint> $points months 0 (today) to RatingForecast::MONTHS
     */
    public function __construct(
        public RatingForecastScenario $scenario,
        public array $points,
        /** The place the last point would have on today's ladder */
        public int $placeAtEnd,
    ) {
    }

    public function end(): RatingForecastPoint
    {
        return $this->points[count($this->points) - 1];
    }
}
