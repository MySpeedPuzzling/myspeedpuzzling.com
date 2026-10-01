<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Results\PuzzleResultAttempt;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class PlayerPuzzleTimesChart
{
    public const string MEDIAN_COLOR = '#8a909c';

    public const string FASTEST_COLOR = '#d4a017';

    public null|string $playerId = null;

    public bool $bare = false;

    public int $height = 200;

    // Optional reference lines across the whole chart: the puzzle's median and fastest time (result detail)
    public null|int $medianTime = null;

    public null|int $fastestTime = null;

    /**
     * @var array<SolvedPuzzle|PuzzleSolver|PuzzleSolversGroup|PuzzleResultAttempt>
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

        $datasets = [
            [
                'data' => $chartData,
                'borderColor' => '#fe4042',
                'borderWidth' => 2,
                'backgroundColor' => 'rgba(254, 64, 66, 0.2)',
                'fill' => true,
                'cubicInterpolationMode' => 'monotone',
                'tension' => 0.4,
            ],
        ];

        // Flat dashed lines, the same colours as the legend under the chart (template)
        foreach ([[$this->medianTime, self::MEDIAN_COLOR], [$this->fastestTime, self::FASTEST_COLOR]] as [$reference, $color]) {
            if ($reference !== null) {
                $datasets[] = [
                    'data' => array_fill(0, count($chartData), $reference),
                    'borderColor' => $color,
                    'borderWidth' => 1.5,
                    'borderDash' => [5, 4],
                    'pointRadius' => 0,
                    'pointHoverRadius' => 0,
                    'fill' => false,
                ];
            }
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => $datasets,
        ]);

        $chart->setOptions([
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    // Flat, thinned-out dates: rotated labels took a third of a small chart's height
                    'ticks' => [
                        'maxRotation' => 0,
                        'autoSkipPadding' => 16,
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
