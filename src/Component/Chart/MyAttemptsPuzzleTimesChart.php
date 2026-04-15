<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class MyAttemptsPuzzleTimesChart
{
    /** @var array<PuzzleSolver|PuzzleSolversGroup> */
    public array $attempts = [];

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function getChart(): Chart
    {
        $sorted = $this->attempts;
        usort($sorted, static function (PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int {
            $aDate = $a->finishedAt ?? new \DateTimeImmutable('@0');
            $bDate = $b->finishedAt ?? new \DateTimeImmutable('@0');

            return $aDate <=> $bDate;
        });

        $labels = [];
        $data = [];

        foreach ($sorted as $attempt) {
            if ($attempt->time === null || $attempt->finishedAt === null) {
                continue;
            }

            $data[] = $attempt->time;
            $labels[] = $attempt->finishedAt->format('d.m.Y');
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
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
