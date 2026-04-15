<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class PlayerPuzzleTimesChart
{
    public null|string $playerId = null;

    public bool $bare = false;

    /**
     * @var array<SolvedPuzzle|PuzzleSolver|PuzzleSolversGroup>
     */
    public array $results = [];

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function getChart(): Chart
    {
        $rows = [];

        foreach ($this->results as $result) {
            if ($result->time === null) {
                continue;
            }

            $date = $result->finishedAt ?? $result->trackedAt;
            $rows[] = ['date' => $date, 'tracked_at' => $result->trackedAt, 'time' => $result->time];
        }

        usort($rows, static fn (array $a, array $b): int => ($a['date'] <=> $b['date']) ?: ($a['tracked_at'] <=> $b['tracked_at']));

        $labels = [];
        $chartData = [];

        foreach ($rows as $row) {
            $chartData[] = $row['time'];
            $labels[] = $row['date']->format('d.m.Y');
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
