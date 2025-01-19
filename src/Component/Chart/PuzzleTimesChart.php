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
        $borderWidths = [];

        foreach ($this->results as $groupedResult) {
            $result = $groupedResult[0];
            $labels[] = $result->playerName;
            $chartData[] = $result->time;

            if ($result->playerId === '018e3842-06c0-72bf-a510-7300844df66a') {
                $backgrounds[] = 'rgba(254, 64, 66, 1)';
                $borderWidths[] = 2;
            } else {
                $backgrounds[] = 'rgba(254, 105, 106, 0.6)';
                $borderWidths[] = 0;
            }
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'backgroundColor' => $backgrounds,
                    'borderColor' => 'rgba(254, 64, 66, 1)',
                    'borderWidth' => $borderWidths,
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
