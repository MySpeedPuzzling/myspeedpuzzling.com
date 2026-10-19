<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Results\PersonalEstimate;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `estimate|estimate_time` → the personal estimate's time as shown ("55min", "3h 10min" - EstimateTime's rounding, in
 * the site's compact format); the templates put "≈" in front (docs/features/insights/estimates.md). Its windows go
 * through prediction_window_range like every other window: `estimate.window|prediction_window_range`.
 */
final class PersonalEstimateTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('estimate_time', $this->time(...)),
        ];
    }

    public function time(PersonalEstimate $estimate): string
    {
        return $this->timeFormatter->compactTime($estimate->roundedSeconds());
    }
}
