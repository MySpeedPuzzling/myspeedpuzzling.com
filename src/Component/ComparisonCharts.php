<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Results\ComparisonChartCard;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Services\ComparisonChartsFactory;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Members' charts on the Charts tab of the compare page (docs/features/player-comparison.md "Charts"): who's ahead
 * puzzle by puzzle, A's time vs B's, pace by piece count, form over time and - with 3+ subjects - the head-to-head grid.
 * Built from the page's result (ComparisonChartsFactory) - no query. The page renders it for members only; a result
 * without a highlighted pair renders nothing.
 */
#[AsTwigComponent]
final class ComparisonCharts
{
    public null|ComparisonResult $result = null;

    /** @var list<ComparisonSubject> */
    public array $subjects = [];

    public function __construct(
        readonly private ComparisonChartsFactory $chartsFactory,
    ) {
    }

    /**
     * @return list<ComparisonChartCard>
     */
    public function getCards(): array
    {
        if ($this->result === null) {
            return [];
        }

        return $this->chartsFactory->cards($this->result, $this->subjects);
    }
}
