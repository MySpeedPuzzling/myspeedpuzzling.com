<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Results\PuzzleSolver;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class PuzzleTimesChart
{
    public string|null $playerId = null;

    /**
     * @var array<array<PuzzleSolver>>
     */
    public array $results = [];

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function getChart(): Chart
    {
        $labels = [];
        $chartData = [];
        $backgrounds = [];

        foreach ($this->results as $groupedResult) {
            $result = $groupedResult[0];
            $labels[] = $result->playerName;
            $chartData[] = $result->time;

            if ($result->playerId === $this->playerId) {
                $backgrounds[] = 'rgba(254, 64, 66, 1)';
            } else {
                $backgrounds[] = 'rgba(254, 105, 106, 0.6)';
            }
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'backgroundColor' => $backgrounds,
                    'data' => $chartData,
                ],
            ],
        ]);

        $chart->setOptions([
            'scales' => [
                'x' => [
                    'ticks' => [
                        'display' => false,
                    ],
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ]);

        return $chart;
    }
}
