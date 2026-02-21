<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class PlayerPuzzleTimesChart
{
    public null|string $playerId = null;

    /**
     * @var array<SolvedPuzzle>
     */
    public array $results = [];

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
        readonly private PuzzlesSorter $puzzlesSorter,
    ) {
    }

    public function getChart(): Chart
    {
        $labels = [];
        $chartData = [];
        $results = $this->puzzlesSorter->sortByFinishedAt($this->results);

        foreach ($results as $result) {
            $chartData[] = $result->time;
            $labels[] = ($result->finishedAt ?? $result->trackedAt)->format('d.m.Y');
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $chartData,
                    'borderColor' => '#fe4042',
                    'borderWidth' => 2,
                    'backgroundColor' => 'rgba(254, 64, 66, 0.2)',
                    'fill' => true,
                    'cubicInterpolationMode' => 'monotone',
                    'tension' => 0.4,
                ],
            ],
        ]);

        $chart->setOptions([
            'scales' => [
                'x' => [
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
