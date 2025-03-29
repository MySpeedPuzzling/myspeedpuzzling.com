<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\ComputeStatistics;
use SpeedPuzzling\Web\Value\Statistics\OverallStatistics;
use SpeedPuzzling\Web\Value\Statistics\PerCategoryStatistics;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PlayerStatistics
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|string $playerId = null;

    #[LiveProp]
    public null|DateTimeImmutable $dateFrom = null;

    #[LiveProp]
    public null|DateTimeImmutable $dateTo = null;

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    public null|Chart $teamPuzzlingTimeChart = null;
    public null|Chart $duoPuzzlingTimeChart = null;
    public null|Chart $soloPuzzlingTimeChart = null;
    public null|Chart $overallPuzzlingTimeChart = null;
    public null|Chart $teamPiecesChart = null;
    public null|Chart $duoPiecesChart = null;
    public null|Chart $soloPiecesChart = null;
    public null|Chart $overallPiecesChart = null;
    public null|Chart $overallManufacturersChart = null;
    public null|Chart $teamManufacturersChart = null;
    public null|Chart $duoManufacturersChart = null;
    public null|Chart $soloManufacturersChart = null;
    public null|OverallStatistics $overallStatistics = null;
    public null|PerCategoryStatistics $teamStatistics = null;
    public null|PerCategoryStatistics $duoStatistics = null;
    public null|PerCategoryStatistics $soloStatistics = null;

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
        readonly private ComputeStatistics $computeStatistics,
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        assert($this->playerId !== null);
        assert($this->dateFrom !== null);
        assert($this->dateTo !== null);

        [$overallStatistics, $soloStatistics, $duoStatistics, $teamStatistics] = $this->computeStatistics->forPlayer(
            playerId: $this->playerId,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            onlyFirstTries: $this->onlyFirstTries,
        );

        $this->soloStatistics = $soloStatistics;
        $this->duoStatistics = $duoStatistics;
        $this->teamStatistics = $teamStatistics;
        $this->overallStatistics = $overallStatistics;
        $this->soloManufacturersChart = $this->getManufacturersChart($soloStatistics);
        $this->duoManufacturersChart = $this->getManufacturersChart($duoStatistics);
        $this->teamManufacturersChart = $this->getManufacturersChart($teamStatistics);
        $this->overallManufacturersChart = $this->getManufacturersChart($overallStatistics);
        $this->overallPiecesChart = $this->getPiecesChart($overallStatistics);
        $this->soloPiecesChart = $this->getPiecesChart($soloStatistics);
        $this->duoPiecesChart = $this->getPiecesChart($duoStatistics);
        $this->teamPiecesChart = $this->getPiecesChart($teamStatistics);
        $this->overallPuzzlingTimeChart = $this->getPuzzlingTimeChart($overallStatistics, $this->dateFrom, $this->dateTo);
        $this->soloPuzzlingTimeChart = $this->getPuzzlingTimeChart($soloStatistics, $this->dateFrom, $this->dateTo);
        $this->duoPuzzlingTimeChart = $this->getPuzzlingTimeChart($duoStatistics, $this->dateFrom, $this->dateTo);
        $this->teamPuzzlingTimeChart = $this->getPuzzlingTimeChart($teamStatistics, $this->dateFrom, $this->dateTo);
    }

    private function getManufacturersChart(OverallStatistics|PerCategoryStatistics $statistics): Chart
    {
        $labels = [];
        $chartData = [];

        foreach ($statistics->solvedPuzzle->countPerManufacturer as $manufacturerName => $count) {
            $chartData[] = $count;
            $labels[] = sprintf('%s (%dx)', $manufacturerName, $count);
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'borderWidth' => 0,
                    'data' => $chartData,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
        ]);

        return $chart;
    }

    private function getPiecesChart(OverallStatistics|PerCategoryStatistics $statistics): Chart
    {
        $labels = [];
        $chartData = [];

        if ($statistics instanceof PerCategoryStatistics) {
            foreach ($statistics->perPieces as $piecesStatistics) {
                $chartData[] = $piecesStatistics->count;
                $labels[] = sprintf('%d: %dx', $piecesStatistics->pieces, $piecesStatistics->count);
            }
        }

        if ($statistics instanceof OverallStatistics) {
            foreach ($statistics->perPiecesCount as $pieces => $count) {
                $chartData[] = $count;
                $labels[] = sprintf('%d: %dx', $pieces, $count);
            }
        }


        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'borderWidth' => 0,
                    'data' => $chartData,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
        ]);

        return $chart;
    }

    private function getPuzzlingTimeChart(
        OverallStatistics|PerCategoryStatistics $statistics,
        DateTimeImmutable $dateFrom,
        DateTimeImmutable $dateTo,
    ): Chart {
        $labels = [];
        $chartData = [];
        $intervalDays = $dateTo->diff($dateFrom)->days;

        // Group by month if the interval is greater than 31 days
        if ($intervalDays > 31) {
            $monthIterator = $dateFrom->modify('first day of this month');

            while ($monthIterator <= $dateTo) {
                $monthKey = $monthIterator->format('m/y');
                $monthStart = $monthIterator->modify('first day of this month');
                $monthEnd = $monthIterator->modify('last day of this month');

                $monthSum = 0;
                for ($day = $monthStart; $day <= $monthEnd && $day <= $dateTo; $day = $day->modify('+1 day')) {
                    $dayKey = $day->format('Y-m-d');
                    $monthSum += $statistics->timeSpentSolving->perDay[$dayKey] ?? 0;
                }

                $labels[] = $monthKey;
                $chartData[] = $monthSum;

                $monthIterator = $monthIterator->modify('+1 month');
            }
        } else {
            // Daily grouping for intervals of 31 days or fewer
            for ($day = $dateFrom; $day <= $dateTo; $day = $day->modify('+1 day')) {
                $dayKey = $day->format('Y-m-d');
                $labels[] = $day->format('d.m.');
                $chartData[] = $statistics->timeSpentSolving->perDay[$dayKey] ?? 0;
            }
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $chartData,
                    'borderColor' => '#fe4042',
                    'borderWidth' => 1,
                    'backgroundColor' => 'rgba(254, 64, 66, 0.2)',
                    'fill' => true,
                    'cubicInterpolationMode' => 'monotone',
                    'tension' => 0.4,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
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
