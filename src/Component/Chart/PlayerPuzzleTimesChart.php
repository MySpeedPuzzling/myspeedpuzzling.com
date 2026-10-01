<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Results\PuzzleResultAttempt;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class PlayerPuzzleTimesChart
{
    public const string MEDIAN_COLOR = '#8a909c';

    public const string FASTEST_COLOR = '#111111';

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
        readonly private TranslatorInterface $translator,
        readonly private PuzzlingTimeFormatter $timeFormatter,
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

        // Flat dashed lines; time_chart_controller.js writes `referenceCaption` onto the chart above each line
        $references = [
            [$this->fastestTime, self::FASTEST_COLOR, 'puzzle_result.chart.fastest'],
            [$this->medianTime, self::MEDIAN_COLOR, 'puzzle_result.chart.median'],
        ];

        foreach ($references as [$reference, $color, $captionKey]) {
            if ($reference !== null) {
                $datasets[] = [
                    'data' => array_fill(0, count($chartData), $reference),
                    'borderColor' => $color,
                    'borderWidth' => 1.5,
                    'borderDash' => [5, 4],
                    'pointRadius' => 0,
                    'pointHoverRadius' => 0,
                    'fill' => false,
                    'referenceCaption' => $this->translator->trans($captionKey) . ' ' . $this->timeFormatter->formatTime($reference),
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
